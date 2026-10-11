<?php

namespace App;

enum OrderServiceLine: string
{
    case GraphicDesign = 'graphic_design';
    case BookBinding = 'book_binding';
    case SewingSublimation = 'sewing_sublimation';
    case TarpaulinPrinting = 'tarpaulin_printing';
    case StickerProduction = 'sticker_production';
    case SintraBoard = 'sintra_board';
    case Signage = 'signage';

    public function label(): string
    {
        return match ($this) {
            self::GraphicDesign => 'Graphic design / layout',
            self::BookBinding => 'Hardbound / book binding',
            self::SewingSublimation => 'Tailoring / sublimation',
            self::TarpaulinPrinting => 'Tarpaulin printing',
            self::StickerProduction => 'Sticker production',
            self::SintraBoard => 'Sintra board',
            self::Signage => 'Signage',
        };
    }
}
