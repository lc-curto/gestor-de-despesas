<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $icons = [
            'bi-tag' => 'tag',
            'bi-house' => 'house',
            'bi-cup-hot' => 'coffee',
            'bi-car-front' => 'car-front',
            'bi-heart-pulse' => 'heart-pulse',
            'bi-controller' => 'gamepad-2',
            'bi-bag' => 'shopping-bag',
            'bi-mortarboard' => 'graduation-cap',
            'bi-wallet2' => 'wallet',
            'bi-shop' => 'store',
            'bi-briefcase' => 'briefcase-business',
            'bi-graph-up-arrow' => 'chart-no-axes-combined',
            'bi-arrow-counterclockwise' => 'undo-2',
            'bi-three-dots' => 'ellipsis',
        ];

        foreach ($icons as $legacy => $lucide) {
            DB::table('categorias')->where('icon', $legacy)->update(['icon' => $lucide]);
        }
    }

    public function down(): void
    {
        $icons = [
            'tag' => 'bi-tag',
            'house' => 'bi-house',
            'coffee' => 'bi-cup-hot',
            'car-front' => 'bi-car-front',
            'heart-pulse' => 'bi-heart-pulse',
            'gamepad-2' => 'bi-controller',
            'shopping-bag' => 'bi-bag',
            'graduation-cap' => 'bi-mortarboard',
            'wallet' => 'bi-wallet2',
            'store' => 'bi-shop',
            'briefcase-business' => 'bi-briefcase',
            'chart-no-axes-combined' => 'bi-graph-up-arrow',
            'undo-2' => 'bi-arrow-counterclockwise',
            'ellipsis' => 'bi-three-dots',
        ];

        foreach ($icons as $lucide => $legacy) {
            DB::table('categorias')->where('icon', $lucide)->update(['icon' => $legacy]);
        }
    }
};
