<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Inventory;
use App\Models\InventoryAlert;
use App\Models\StockIn;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;

class BackfillInventoryAuditLog extends Command
{
    protected $signature = 'audit:backfill-inventory {--dry-run : Show what would be added without saving anything}';

    protected $description = 'Add audit log entries for inventory activity that was recorded before the audit log existed';

    /** @var array<string, int> */
    private array $added = [];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        Inventory::query()->orderBy('inventory_id')->each(function (Inventory $item) use ($dryRun): void {
            $this->import(
                $dryRun,
                "inventory:{$item->inventory_id}",
                'inventory.created',
                null,
                "Material \"{$item->material_name}\" was added to the inventory.",
                $item,
                $item->created_at,
            );
        });

        StockIn::query()->with(['inventory', 'staff'])->orderBy('stock_in_id')->each(function (StockIn $stockIn) use ($dryRun): void {
            $item = $stockIn->inventory;

            if ($item === null) {
                return;
            }

            $this->import(
                $dryRun,
                "stock_in:{$stockIn->stock_in_id}",
                'inventory.stock_added',
                $stockIn->staff,
                "Added {$this->plain($stockIn->quantity_added)} {$item->unit} to \"{$item->material_name}\".",
                $item,
                $stockIn->date_added,
                ['quantity_added' => $this->plain($stockIn->quantity_added), 'unit' => $item->unit],
            );
        });

        InventoryAlert::query()->with(['inventory', 'staff'])->where('alert_type', 'low_stock')->orderBy('alert_id')->each(function (InventoryAlert $alert) use ($dryRun): void {
            $item = $alert->inventory;

            if ($item === null) {
                return;
            }

            $remarks = $alert->remarks !== null && trim($alert->remarks) !== '' ? ' Remarks: '.trim($alert->remarks) : '';

            $this->import(
                $dryRun,
                "alert:{$alert->alert_id}",
                'inventory.low_stock_reported',
                $alert->staff,
                "Reported \"{$item->material_name}\" as low on stock.{$remarks}",
                $item,
                $alert->alert_date,
            );
        });

        $this->newLine();
        $this->info($dryRun ? 'Dry run: nothing was saved. This is what would be added:' : 'Done. Entries added:');

        foreach (['inventory.created' => 'Materials', 'inventory.stock_added' => 'Stock additions', 'inventory.low_stock_reported' => 'Low-stock reports'] as $action => $label) {
            $this->line(sprintf('  %-18s %d', $label, $this->added[$action] ?? 0));
        }

        $this->newLine();
        $this->comment('Stock deductions, material edits and deletions made before the audit log existed were never recorded, so they cannot be added.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function import(
        bool $dryRun,
        string $key,
        string $action,
        ?User $actor,
        string $description,
        Inventory $item,
        ?CarbonInterface $at,
        array $details = [],
    ): void {
        // Running the command again never duplicates an entry.
        if (AuditLog::query()->where('details->backfill_key', $key)->exists()) {
            return;
        }

        $this->added[$action] = ($this->added[$action] ?? 0) + 1;

        if ($dryRun) {
            return;
        }

        $log = new AuditLog;
        $log->forceFill([
            'user_id' => $actor?->id,
            'actor_name' => $actor?->name ?? 'Unknown (before the audit log)',
            'actor_role' => $actor?->role,
            'action' => $action,
            'subject_type' => 'inventory',
            'subject_id' => $item->inventory_id,
            'subject_label' => $item->material_name,
            'description' => $description,
            'details' => $details + ['backfilled' => true, 'backfill_key' => $key],
            'created_at' => $at ?? now(),
        ])->save();
    }

    private function plain(string|float|null $number): string
    {
        if ($number === null || ! is_numeric($number)) {
            return (string) $number;
        }

        return rtrim(rtrim(number_format((float) $number, 3, '.', ''), '0'), '.');
    }
}