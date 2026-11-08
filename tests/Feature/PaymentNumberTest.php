<?php

namespace Tests\Feature;

use App\Models\PaymentNumber;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentNumberTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private Restaurant $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::factory()->create();
        $this->other = Restaurant::factory()->create();
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create([
            'restaurant_id' => $this->restaurant->id,
            'role' => $role,
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function number(?Restaurant $restaurant = null, array $attributes = []): PaymentNumber
    {
        return PaymentNumber::create(array_merge([
            'restaurant_id' => ($restaurant ?? $this->restaurant)->id,
            'operator' => 'mtn_momo',
            'phone_number' => '+22997001122',
            'beneficiary_name' => 'Chez Awa - Caisse Principale',
            'is_active' => true,
        ], $attributes));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'operator' => 'mtn_momo',
            'phone_number' => '+229 97 00 11 22',
            'beneficiary_name' => 'Chez Awa - Caisse Principale',
        ], $overrides);
    }

    public function test_admin_and_manager_can_register_a_number_which_is_normalized(): void
    {
        foreach (['admin', 'manager'] as $index => $role) {
            $this->actingAsRole($role);

            $this->postJson('/api/payment-numbers', $this->payload(['phone_number' => '+229 97 00 11 2'.$index]))
                ->assertCreated()
                ->assertJsonPath('operator', 'mtn_momo')
                ->assertJsonPath('phone_number', '+2299700112'.$index)
                ->assertJsonPath('is_active', true);

            $this->assertDatabaseHas('payment_numbers', [
                'restaurant_id' => $this->restaurant->id,
                'phone_number' => '+2299700112'.$index,
                'beneficiary_name' => 'Chez Awa - Caisse Principale',
            ]);
        }

        $this->assertDatabaseHas('activity_logs', ['action' => 'payment_number.created']);
    }

    public function test_only_admin_and_manager_can_modify_numbers(): void
    {
        $number = $this->number();

        foreach (['waiter', 'cashier', 'kitchen'] as $role) {
            $this->actingAsRole($role);

            $this->postJson('/api/payment-numbers', $this->payload(['phone_number' => '+22990000000']))
                ->assertForbidden();
            $this->patchJson("/api/payment-numbers/{$number->id}", ['is_active' => false])
                ->assertForbidden();
            $this->deleteJson("/api/payment-numbers/{$number->id}")
                ->assertForbidden();
        }

        $this->assertDatabaseCount('payment_numbers', 1);
        $this->assertTrue($number->fresh()->is_active);
    }

    public function test_invalid_numbers_are_refused(): void
    {
        $this->actingAsRole('admin');

        $invalid = [
            'opérateur inconnu' => ['operator' => 'orange_money'],
            'sans indicatif' => ['phone_number' => '97001122'],
            'autre pays' => ['phone_number' => '+225 05 44 11 22 33'],
            'trop court' => ['phone_number' => '+229 9700112'],
            'trop long' => ['phone_number' => '+229 97 00 11 22 33 4'],
            'avec des lettres' => ['phone_number' => '+229 97 00 AB 22'],
            'sans bénéficiaire' => ['beneficiary_name' => ''],
        ];

        foreach ($invalid as $label => $override) {
            $this->postJson('/api/payment-numbers', $this->payload($override))
                ->assertStatus(422);
        }

        $this->assertDatabaseCount('payment_numbers', 0);

        // les numéros de 8 et de 10 chiffres sont acceptés
        $this->postJson('/api/payment-numbers', $this->payload(['phone_number' => '+229 01 97 00 11 22']))
            ->assertCreated();
        $this->assertDatabaseCount('payment_numbers', 1);
    }

    public function test_the_same_number_cannot_be_registered_twice_but_another_restaurant_can_use_it(): void
    {
        $this->actingAsRole('admin');

        $this->postJson('/api/payment-numbers', $this->payload())->assertCreated();
        $this->postJson('/api/payment-numbers', $this->payload(['beneficiary_name' => 'Autre']))
            ->assertStatus(422);
        $this->assertDatabaseCount('payment_numbers', 1);

        Sanctum::actingAs(User::factory()->create([
            'restaurant_id' => $this->other->id,
            'role' => 'admin',
        ]));

        $this->postJson('/api/payment-numbers', $this->payload())->assertCreated();
    }

    public function test_staff_can_read_only_the_numbers_of_their_restaurant(): void
    {
        $mtn = $this->number();
        $moov = $this->number(null, [
            'operator' => 'moov_money',
            'phone_number' => '+22961000000',
            'is_active' => false,
        ]);
        $this->number($this->other, ['phone_number' => '+22990000001']);

        foreach (['admin', 'manager', 'cashier', 'waiter'] as $role) {
            $this->actingAsRole($role);

            $ids = collect($this->getJson('/api/payment-numbers')->assertOk()->json())->pluck('id');

            $this->assertEqualsCanonicalizing([$mtn->id, $moov->id], $ids->all(), "rôle {$role}");
        }

        $this->actingAsRole('kitchen');
        $this->getJson('/api/payment-numbers')->assertForbidden();
    }

    public function test_the_list_can_be_filtered_by_operator_and_by_active_state(): void
    {
        $mtn = $this->number();
        $moov = $this->number(null, [
            'operator' => 'moov_money',
            'phone_number' => '+22961000000',
            'is_active' => false,
        ]);
        $this->actingAsRole('waiter');

        $active = collect($this->getJson('/api/payment-numbers?active=1')->assertOk()->json())->pluck('id')->all();
        $this->assertSame([$mtn->id], $active);

        $moovOnly = collect($this->getJson('/api/payment-numbers?operator=moov_money')->assertOk()->json())->pluck('id')->all();
        $this->assertSame([$moov->id], $moovOnly);
    }

    public function test_admin_and_manager_can_rename_and_toggle_a_number_but_not_in_another_restaurant(): void
    {
        $number = $this->number();
        $foreign = $this->number($this->other, ['phone_number' => '+22990000001']);

        foreach (['admin', 'manager'] as $role) {
            $this->actingAsRole($role);

            $this->patchJson("/api/payment-numbers/{$number->id}", [
                'is_active' => false,
                'beneficiary_name' => "Caisse {$role}",
            ])
                ->assertOk()
                ->assertJsonPath('is_active', false)
                ->assertJsonPath('beneficiary_name', "Caisse {$role}");

            // l'appel précédent prouve que la route existe : ce 404 est bien un refus d'isolation
            $this->patchJson("/api/payment-numbers/{$foreign->id}", ['is_active' => false])->assertNotFound();
        }

        $this->assertTrue($foreign->fresh()->is_active);

        // le numéro et l'opérateur ne se modifient pas
        $this->patchJson("/api/payment-numbers/{$number->id}", ['phone_number' => '+22900000000'])->assertOk();
        $this->assertSame('+22997001122', $number->fresh()->phone_number);
    }

    public function test_admin_and_manager_can_delete_a_number_but_not_one_of_another_restaurant(): void
    {
        $first = $this->number();
        $second = $this->number(null, ['phone_number' => '+22961000000']);
        $foreign = $this->number($this->other, ['phone_number' => '+22990000001']);

        $this->actingAsRole('admin');
        $this->deleteJson("/api/payment-numbers/{$first->id}")->assertNoContent();
        $this->assertDatabaseMissing('payment_numbers', ['id' => $first->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'payment_number.deleted']);

        $this->actingAsRole('manager');
        $this->deleteJson("/api/payment-numbers/{$second->id}")->assertNoContent();

        // les deux suppressions précédentes prouvent que la route existe
        $this->deleteJson("/api/payment-numbers/{$foreign->id}")->assertNotFound();
        $this->assertDatabaseHas('payment_numbers', ['id' => $foreign->id]);
    }

    public function test_numbers_require_authentication(): void
    {
        $this->getJson('/api/payment-numbers')->assertUnauthorized();
    }
}
