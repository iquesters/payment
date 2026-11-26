<?php

namespace Iquesters\Payment\Tests;

use Diglactic\Breadcrumbs\Breadcrumbs;
use Diglactic\Breadcrumbs\ServiceProvider as BreadcrumbsServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Iquesters\Commerce\CommerceServiceProvider;
use Iquesters\Commerce\Models\Order;
use Iquesters\Commerce\Models\Product;
use Iquesters\Commerce\Services\OrderService;
use Iquesters\Foundation\FoundationServiceProvider;
use Iquesters\Foundation\Support\ConfProvider;
use Iquesters\Organisation\Models\Organisation;
use Iquesters\Organisation\OrganisationServiceProvider;
use Iquesters\Organisation\Support\OrganisationRoles;
use Iquesters\Payment\PaymentServiceProvider;
use Iquesters\Payment\Support\PaymentAccess;
use Iquesters\Payment\Tests\Fixtures\User;
use Iquesters\UserInterface\UserInterfaceServiceProvider;
use Laravel\Sanctum\SanctumServiceProvider;
use Orchestra\Testbench\Attributes\WithMigration;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionServiceProvider;

#[WithMigration]
abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Settings are cached before the test's transaction starts: start clean.
        Cache::flush();
        ConfProvider::forget();
        PaymentAccess::forget();
    }

    protected function getPackageProviders($app): array
    {
        return [
            PermissionServiceProvider::class,
            SanctumServiceProvider::class,
            BreadcrumbsServiceProvider::class,
            FoundationServiceProvider::class,
            UserInterfaceServiceProvider::class,
            OrganisationServiceProvider::class,
            CommerceServiceProvider::class,
            PaymentServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return ['Breadcrumbs' => Breadcrumbs::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('permission.cache.store', 'array');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }

    protected function makeUser(?string $role = null): User
    {
        $user = User::create([
            'name' => 'Test '.Str::random(6),
            'status' => 'active',
            'email' => Str::random(10).'@example.test',
            'password' => bcrypt(Str::random(20)),
        ]);

        if ($role) {
            Role::findOrCreate($role, 'web');
            $user->assignRole($role);
        }

        return $user;
    }

    protected function makeOrganisation(string $name = 'TTVians'): Organisation
    {
        return Organisation::create([
            'uid' => (string) Str::ulid(),
            'name' => $name,
            'description' => "{$name} organisation",
            'status' => 'active',
        ]);
    }

    /** A user of the given role who belongs to the organisation (an "admin" member is its admin). */
    protected function memberOf(Organisation $organisation, string $role = 'user'): User
    {
        $user = $this->makeUser($role);
        // An "admin" member is org-admin there (per-organisation role); anyone else a member.
        OrganisationRoles::join($user, $organisation, $role === 'admin' ? 'org-admin' : 'member');

        return $user;
    }

    protected function makeProduct(Organisation $organisation, array $attributes = []): Product
    {
        return Product::create($attributes + [
            'organisation_id' => $organisation->id,
            'name' => 'Meet pass',
            'type' => 'ticket',
            'price' => 600,
            'currency' => 'INR',
        ]);
    }

    /** An order of one pass (₹600 by default) bought by $buyer; references TTV-MEET-0001, … */
    protected function makeOrder(Organisation $organisation, ?User $buyer = null, float $price = 600): Order
    {
        $product = $this->makeProduct($organisation, ['price' => $price]);

        return app(OrderService::class)->create(
            $organisation,
            ['user_id' => $buyer?->id, 'name' => $buyer?->name ?? 'Rahul Sen', 'phone' => '9800000001'],
            [['product' => $product]],
            ['prefix' => 'TTV-MEET']
        );
    }
}
