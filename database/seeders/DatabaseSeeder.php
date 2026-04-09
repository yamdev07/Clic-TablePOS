<?php

// database/seeders/DatabaseSeeder.php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('🚀 Démarrage du seeding Clic&Table...');

        // 1. Créer le restaurant (avec namespace complet)
        $restaurantId = (string) Str::uuid();

        Restaurant::create([
            'id' => $restaurantId,
            'name' => 'Clic&Table Café',
            'slug' => 'clicettable-cafe',
            'email' => 'contact@clicettable.com',
            'phone' => '+221 78 123 45 67',
            'address' => 'Dakar, Sénégal',
            'currency' => 'XOF',
            'status' => 'active',
        ]);

        $this->command->info('✅ Restaurant créé');

        // 2. Créer les utilisateurs
        $users = [
            [
                'name' => 'Admin Clic&Table',
                'email' => 'admin@clicettable.com',
                'role' => 'admin',
                'password' => 'password123',
            ],
            [
                'name' => 'Jean Serveur',
                'email' => 'serveur@clicettable.com',
                'role' => 'waiter',
                'password' => 'password123',
            ],
            [
                'name' => 'Pierre Cuisinier',
                'email' => 'cuisine@clicettable.com',
                'role' => 'kitchen',
                'password' => 'password123',
            ],
            [
                'name' => 'Marie Caissière',
                'email' => 'cashier@clicettable.com',
                'role' => 'cashier',
                'password' => 'password123',
            ],
            [
                'name' => 'Paul Manager',
                'email' => 'manager@clicettable.com',
                'role' => 'manager',
                'password' => 'password123',
            ],
        ];

        foreach ($users as $userData) {
            User::create([
                'id' => (string) Str::uuid(),
                'restaurant_id' => $restaurantId,
                'name' => $userData['name'],
                'email' => $userData['email'],
                'password' => Hash::make($userData['password']),
                'role' => $userData['role'],
                'is_active' => true,
            ]);
        }

        $this->command->info('✅ '.count($users).' utilisateurs créés');

        // 3. Créer les catégories
        $categories = [
            ['name' => 'Entrées', 'display_order' => 1],
            ['name' => 'Plats Principaux', 'display_order' => 2],
            ['name' => 'Grillades', 'display_order' => 3],
            ['name' => 'Desserts', 'display_order' => 4],
            ['name' => 'Boissons', 'display_order' => 5],
        ];

        $categoryIds = [];
        foreach ($categories as $catData) {
            $category = Category::create([
                'id' => (string) Str::uuid(),
                'restaurant_id' => $restaurantId,
                'name' => $catData['name'],
                'display_order' => $catData['display_order'],
                'is_active' => true,
            ]);
            $categoryIds[$catData['name']] = $category->id;
        }

        $this->command->info('✅ Catégories créées');

        // 4. Créer les plats
        $menuItems = [
            ['name' => 'Salade César', 'price' => 3500, 'category' => 'Entrées', 'preparation_time' => 10],
            ['name' => 'Frites Maison', 'price' => 2000, 'category' => 'Entrées', 'preparation_time' => 8],
            ['name' => 'Beignets de Poisson', 'price' => 4000, 'category' => 'Entrées', 'preparation_time' => 12],
            ['name' => 'Samoussas (x4)', 'price' => 2500, 'category' => 'Entrées', 'preparation_time' => 10],
            ['name' => 'Burger Clic&Table', 'price' => 5500, 'category' => 'Plats Principaux', 'preparation_time' => 15],
            ['name' => 'Poulet DG', 'price' => 6500, 'category' => 'Plats Principaux', 'preparation_time' => 20],
            ['name' => 'Mafé Riz', 'price' => 5500, 'category' => 'Plats Principaux', 'preparation_time' => 20],
            ['name' => 'Yassa Poulet', 'price' => 6000, 'category' => 'Plats Principaux', 'preparation_time' => 18],
            ['name' => 'Thieboudienne', 'price' => 7500, 'category' => 'Plats Principaux', 'preparation_time' => 25],
            ['name' => 'Brochettes (x5)', 'price' => 4500, 'category' => 'Grillades', 'preparation_time' => 15],
            ['name' => 'Poulet Grillé', 'price' => 5500, 'category' => 'Grillades', 'preparation_time' => 20],
            ['name' => 'Poisson Grillé', 'price' => 7000, 'category' => 'Grillades', 'preparation_time' => 20],
            ['name' => 'Tiramisu', 'price' => 3000, 'category' => 'Desserts', 'preparation_time' => 5],
            ['name' => 'Glace Vanille', 'price' => 1500, 'category' => 'Desserts', 'preparation_time' => 3],
            ['name' => 'Fruit de saison', 'price' => 2000, 'category' => 'Desserts', 'preparation_time' => 5],
            ['name' => 'Coca Cola', 'price' => 1000, 'category' => 'Boissons', 'preparation_time' => 2],
            ['name' => 'Jus de Bissap', 'price' => 1200, 'category' => 'Boissons', 'preparation_time' => 3],
            ['name' => 'Jus de Bouye', 'price' => 1200, 'category' => 'Boissons', 'preparation_time' => 3],
            ['name' => 'Eau Minérale', 'price' => 800, 'category' => 'Boissons', 'preparation_time' => 1],
            ['name' => 'Café', 'price' => 1000, 'category' => 'Boissons', 'preparation_time' => 5],
        ];

        foreach ($menuItems as $item) {
            MenuItem::create([
                'id' => (string) Str::uuid(),
                'restaurant_id' => $restaurantId,
                'category_id' => $categoryIds[$item['category']],
                'name' => $item['name'],
                'price' => $item['price'],
                'preparation_time' => $item['preparation_time'],
                'is_available' => true,
                'is_active' => true,
            ]);
        }

        $this->command->info('✅ '.count($menuItems).' plats créés');

        // 5. Créer les tables
        for ($i = 1; $i <= 12; $i++) {
            $row = floor(($i - 1) / 4);
            $col = ($i - 1) % 4;

            Table::create([
                'id' => (string) Str::uuid(),
                'restaurant_id' => $restaurantId,
                'number' => (string) $i,
                'name' => "Table $i",
                'capacity' => $i % 3 == 0 ? 6 : 4,
                'status' => 'free',
                'x_position' => $col * 150 + 50,
                'y_position' => $row * 150 + 50,
                'qr_code' => 'https://clicettable.com/t/'.Str::random(8),
            ]);
        }

        $this->command->info('✅ 12 tables créées');

        $this->command->newLine();
        $this->command->info('🎉 BASE DE DONNÉES INITIALISÉE AVEC SUCCÈS !');
        $this->command->info('📧 Comptes de test : admin@clicettable.com / password123');
        $this->command->newLine();
    }
}
