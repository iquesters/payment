<?php

namespace Iquesters\Payment\Database\Seeders;

use Illuminate\Support\Facades\DB;
use Iquesters\Foundation\Database\Seeders\BaseSeeder;
use Iquesters\Foundation\Models\Module;
use Iquesters\Payment\Support\PaymentForms;
use Spatie\Permission\Models\Role;

class PaymentSeeder extends BaseSeeder
{
    /** Sees Payments and Payment settings (data limited to the organisations they administer). */
    public const MANAGE_PERMISSION = 'manage-payments';

    /** Super-admins and organisation admins ("admin"). */
    public const MODULE_ROLES = ['super-admin', 'admin'];

    public const PAYMENT_TABLE = 'payment-table';

    public const ROW_COMPONENT = 'payment.summary';

    protected string $moduleName = 'payment';

    protected string $description = 'payment module: UPI (QR per order) and cash payments of orders, and their verification';

    protected array $metas = [
        'module_icon' => 'fas fa-indian-rupee-sign',
        'module_sidebar_menu' => [
            [
                'key' => 'payments',
                'icon' => 'fas fa-money-check',
                'label' => 'Payments',
                'permission' => self::MANAGE_PERMISSION,
                // The generic table: payments sent, waiting ones first (limited to the organisations administered).
                'table_schema' => [
                    'slug' => self::PAYMENT_TABLE,
                    'name' => 'Payments',
                    'description' => 'Payments people sent; check each against the bank statement',
                    'schema' => [
                        'entity' => 'payments',
                        'data_url' => '/payment/payments/data',
                        'create' => ['form' => PaymentForms::MARK_PAID, 'label' => 'Record a payment'],
                        // Each row as a card: the order, a coloured status badge, amount, UTR, a rejection's reason.
                        'row_component' => self::ROW_COMPONENT,
                        'record' => [
                            ['html' => '/payment/payments/{uid}/summary', 'title' => 'Payment'],
                            ['form' => PaymentForms::DECISION, 'mode' => 'edit', 'title' => 'Verify or reject', 'when' => ['is_open' => '1']],
                        ],
                        'dt-options' => [
                            'columns' => [
                                ['data' => 'reference', 'title' => 'Order', 'visible' => true, 'link' => true, 'form-schema-uid' => PaymentForms::DECISION],
                                ['data' => 'buyer', 'title' => 'Paid by', 'visible' => true],
                                ['data' => 'expected', 'title' => 'Expected', 'visible' => true],
                                ['data' => 'amount_label', 'title' => 'Amount', 'visible' => true],
                                ['data' => 'utr', 'title' => 'UTR', 'visible' => true],
                                ['data' => 'kind', 'title' => 'By', 'visible' => true],
                                ['data' => 'status_label', 'title' => 'Status', 'visible' => true],
                                ['data' => 'organisation', 'title' => 'Organisation', 'visible' => true],
                                ['data' => 'submitted_at', 'title' => 'Sent', 'visible' => true],
                            ],
                            'options' => ['pageLength' => 25, 'order' => [], 'responsive' => true],
                        ],
                        'default_view_mode' => 'inbox',
                    ],
                ],
            ],
            [
                'key' => 'payment-settings',
                'icon' => 'fas fa-gear',
                'label' => 'Payment settings',
                'route' => 'payment.settings.index',
                'permission' => self::MANAGE_PERMISSION,
            ],
        ],
    ];

    protected array $permissions = [
        self::MANAGE_PERMISSION,
    ];

    protected function seedCustom(): void
    {
        $roles = collect(self::MODULE_ROLES)->mapWithKeys(fn (string $name) => [$name => Role::findOrCreate($name, 'web')]);
        $roles['admin']->givePermissionTo(self::MANAGE_PERMISSION);
        Module::where('name', $this->moduleName)->first()?->assignRoles($roles->pluck('id')->all());

        // The module's form schemas (rebuilt on every seed).
        PaymentForms::syncAll();

        // A table made before the row card: given it (an admin's own row_component is kept).
        $table = DB::table('table_schemas')->where('slug', self::PAYMENT_TABLE)->first();
        $schema = $table ? (array) json_decode((string) $table->schema, true) : null;
        if ($schema !== null && empty($schema['row_component'])) {
            $schema['row_component'] = self::ROW_COMPONENT;
            DB::table('table_schemas')->where('id', $table->id)->update(['schema' => json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_at' => now()]);
        }
    }
}
