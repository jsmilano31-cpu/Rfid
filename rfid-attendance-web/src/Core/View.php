<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class View
{
    public static function partial(string $view, array $data = []): void
    {
        $viewFile = BASE_PATH . '/templates/' . $view . '.php';
        if (!is_file($viewFile)) {
            throw new RuntimeException('View template not found.');
        }
        extract($data, EXTR_SKIP);
        require $viewFile;
    }

    public static function render(string $view, array $data = [], string $layout = 'app'): void
    {
        $viewFile = BASE_PATH . '/templates/' . $view . '.php';
        $layoutFile = BASE_PATH . '/templates/layouts/' . $layout . '.php';
        if (!is_file($viewFile) || !is_file($layoutFile)) {
            throw new RuntimeException('View template not found.');
        }

        extract($data, EXTR_SKIP);
        ob_start();
        require $viewFile;
        $content = (string) ob_get_clean();
        require $layoutFile;
    }
}
