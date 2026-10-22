<?php

namespace Tests\Feature;

use Tests\TestCase;

/** #18: overflow-x on <body> makes body a scroll container and breaks Filament's sticky topbar. */
class Phase207AdminTopbarStickyTest extends TestCase
{
    public function test_admin_polish_does_not_clip_body_overflow(): void
    {
        $css = file_get_contents(resource_path('views/partials/admin-polish.blade.php'));
        $this->assertStringContainsString('html{overflow-x:hidden}', $css);
        $this->assertDoesNotMatchRegularExpression('/body\s*[,{][^}]*overflow-x/', $css);
    }

    public function test_bcmath_is_declared(): void
    {
        $json = json_decode(file_get_contents(base_path('composer.json')), true);
        $this->assertArrayHasKey('ext-bcmath', $json['require']);
        $this->assertSame('8.2.0', $json['config']['platform']['php']);
    }
}
