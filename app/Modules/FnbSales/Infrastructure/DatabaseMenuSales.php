<?php

declare(strict_types=1);

namespace App\Modules\FnbSales\Infrastructure;

use App\Modules\FnbSales\Application\MenuSales;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseMenuSales implements MenuSales
{
    public function soldBetween(PropertyId $property, string $from, string $to): array
    {
        $rows = DB::table('fnb_bill_lines as l')->join('fnb_bills as b', 'b.id', '=', 'l.bill_id')->join('fnb_menu_items as i', 'i.id', '=', 'l.item_id')->join('fnb_menu_categories as c', 'c.id', '=', 'i.category_id')
            ->join('fnb_outlets as o', 'o.id', '=', 'b.outlet_id')
            ->where('b.property_id', $property->toString())->where('b.status', 'settled')->whereBetween('b.business_date', [$from, $to])->whereIn('l.status', ['pending', 'sent'])
            ->groupBy('l.item_id', 'i.code', 'i.name', 'c.name', 'b.outlet_id', 'o.name')
            ->get(['l.item_id', 'i.code', 'i.name', 'c.name as category', 'b.outlet_id', 'o.name as outlet', DB::raw('SUM(l.quantity) as portions'),
                // The share of a bill that is the dish's own price: what the bill kept after the service charge and the tax came off, in proportion to the line.
                DB::raw('SUM(ROUND(l.line_total_minor * b.base_minor / b.subtotal_minor)) as net'), DB::raw('SUM(l.discount_minor) as discount')]);

        return array_values(array_map(static fn (object $r): array => [
            'item_id' => (string) $r->item_id, 'code' => (string) $r->code, 'name' => (string) $r->name, 'category' => (string) $r->category, 'outlet_id' => (string) $r->outlet_id, 'outlet' => (string) $r->outlet,
            'portions' => (int) $r->portions, 'net_minor' => (int) $r->net, 'discount_minor' => (int) $r->discount,
        ], $rows->all()));
    }
}
