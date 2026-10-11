<?php

namespace App;

enum StaffSpecialtyType: string
{
    case GraphicDesigner = 'graphic_designer';
    case BookBinder = 'book_binder';
    case Sewer = 'sewer';
    case TarpaulinEditor = 'tarpaulin_editor';
    case StickerEditor = 'sticker_editor';
    case MachineOperator = 'machine_operator';
    case InventoryClerk = 'inventory_clerk';

    public function label(): string
    {
        return match ($this) {
            self::GraphicDesigner => 'Graphic designer',
            self::BookBinder => 'Book binder',
            self::Sewer => 'Sewer',
            self::TarpaulinEditor => 'Tarpaulin editor',
            self::StickerEditor => 'Sticker editor',
            self::MachineOperator => 'Machine operator',
            self::InventoryClerk => 'Inventory clerk',
        };
    }
}
