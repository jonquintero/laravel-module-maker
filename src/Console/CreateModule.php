<?php

namespace Jonquintero\ModuleMaker\Console;

use Doctrine\Inflector\InflectorFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class CreateModule extends Command
{
    protected $signature = 'module:create {name}';
    protected $description = 'Create a new module';

    public function handle(): void
    {
        $moduleName = $this->argument('name');

        $modulesRoot = config('module-maker.modules_path', base_path('modules'));
        $modulePath  = rtrim($modulesRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $moduleName;

        if (File::exists($modulePath)) {
            $this->error('Module already exists!');
            return;
        }

        File::makeDirectory($modulePath, 0755, true);

        $this->generateFolders($moduleName, $modulePath);
        $this->generateFiles($moduleName, $modulePath);

        // Autoload PSR-4 y registro del provider del módulo
        $this->ensureModulesPsr4Autoload();
        $this->autoRegisterModuleProvider($moduleName);

        $this->info("Module {$moduleName} created successfully!");
    }

    /* ==================== GENERACIÓN ==================== */

    protected function generateFolders(string $moduleName, string $modulePath): void
    {
        $folders = [
            'Actions',
            'Database/Factories',
            'Database/Migrations',
            'DTO',
            'Http/Controllers',
            'Http/Requests',
            'Http/Resources',
            'Models',
            'Providers',
            'routes',
            'Tests',
        ];

        foreach ($folders as $folder) {
            File::makeDirectory($modulePath . DIRECTORY_SEPARATOR . $folder, 0755, true);
        }
    }

    protected function resolveStubsPath(): string
    {
        $published = config('module-maker.stubs_path', base_path('stubs/vendor/module-maker/module'));
        if (File::isDirectory($published)) {
            return $published;
        }
        return __DIR__ . '/../stubs/module';
    }

    protected function generateFiles(string $moduleName, string $modulePath): void
    {
        $stubPath = $this->resolveStubsPath();

        // Copiamos todos los stubs al destino
        File::copyDirectory($stubPath, $modulePath);

        // Stubs "semilla" que NO deben quedar en el módulo
        $seedStubs = [
            'Models' . DIRECTORY_SEPARATOR . 'modelName.php.stub',
            'Http' . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . 'controllerName.php.stub',
            'Database' . DIRECTORY_SEPARATOR . 'Migrations' . DIRECTORY_SEPARATOR . 'migration.php.stub',
            'Http' . DIRECTORY_SEPARATOR . 'Requests' . DIRECTORY_SEPARATOR . 'Request.php.stub',
        ];

        // Eliminamos del módulo los stubs semilla copiados (los usaremos desde el paquete)
        foreach ($seedStubs as $rel) {
            $p = $modulePath . DIRECTORY_SEPARATOR . $rel;
            if (is_file($p)) {
                @unlink($p);
            }
        }

        // Procesar el resto de archivos (renombrar + reemplazar contenidos)
        $files = File::allFiles($modulePath);

        foreach ($files as $file) {
            $origPath = $file->getPathname();
            $relDir   = $file->getRelativePath();

            $fileName = $file->getFilename();

            // Regla especial: resourceName -> {Module}Resource
            if (str_contains($fileName, 'resourceName')) {
                $newFileName = str_replace(
                    ['resourceName', '.stub'],
                    [$moduleName . 'Resource', ''],
                    $fileName
                );
            } else {
                // Reemplazo genérico de nombre
                $newFileName = str_replace(['{{moduleName}}', '.stub'], [$moduleName, ''], $fileName);
            }

            $targetPath = $modulePath . DIRECTORY_SEPARATOR . $relDir . DIRECTORY_SEPARATOR . $newFileName;

            // Contenido con placeholders reemplazados
            $contents = file_get_contents($origPath);
            if (!is_string($contents)) {
                $contents = '';
            } else {
                $contents = str_replace('{{moduleName}}', $moduleName, $contents);
                // Por si acaso usaste 'resourceName' dentro del código del stub:
                $contents = str_replace('resourceName', $moduleName.'Resource', $contents);
            }

            $this->safeReplaceFile($targetPath, $contents);
            @unlink($origPath);
        }

        // Generar artefactos desde stubs semilla del paquete (no los copiados)
        $this->generateModel($moduleName, $modulePath);
        $this->generateController($moduleName, $modulePath);
        $this->generateMigration($moduleName, $modulePath);
        $this->generateFormRequest($moduleName, $modulePath);
    }

    protected function safeReplaceFile(string $targetPath, string $contents, int $retries = 5, int $sleepMs = 120): void
    {
        $dir = dirname($targetPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        for ($i = 0; $i < $retries; $i++) {
            if (!file_exists($targetPath) || @unlink($targetPath)) {
                break;
            }
            usleep($sleepMs * 1000);
        }

        $tmp = $targetPath . '.tmp' . bin2hex(random_bytes(4));
        file_put_contents($tmp, $contents);

        for ($i = 0; $i < $retries; $i++) {
            if (@rename($tmp, $targetPath)) {
                return;
            }
            usleep($sleepMs * 1000);
        }

        @copy($tmp, $targetPath);
        @unlink($tmp);
    }

    protected function generateModel(string $moduleName, string $modulePath): void
    {
        $modelName = ucfirst($moduleName);
        $stub = $this->resolveStubsPath() . '/Models/modelName.php.stub';
        if (is_file($stub)) {
            $content = file_get_contents($stub);
            $content = str_replace(['{{modelName}}', '{{moduleName}}'], [$modelName, $moduleName], $content);
            file_put_contents($modulePath . '/Models/' . $modelName . '.php', $content);
        }
    }

    protected function generateController(string $moduleName, string $modulePath): void
    {
        $controllerName = ucfirst($moduleName) . 'Controller';
        $stub = $this->resolveStubsPath() . '/Http/Controllers/controllerName.php.stub';
        if (is_file($stub)) {
            $content = file_get_contents($stub);
            $content = str_replace(['{{controllerName}}', '{{moduleName}}'], [$controllerName, $moduleName], $content);
            file_put_contents($modulePath . '/Http/Controllers/' . $controllerName . '.php', $content);
        }
    }

    protected function generateMigration(string $moduleName, string $modulePath): void
    {
        $singularName = Str::snake($moduleName);
        $pluralName = InflectorFactory::create()->build()->pluralize($singularName);
        $migrationName = 'create_' . $pluralName . '_table';

        $stub = $this->resolveStubsPath() . '/Database/Migrations/migration.php.stub';
        if (is_file($stub)) {
            $timestamp = date('Y_m_d_His');
            $file = $timestamp . '_' . $migrationName . '.php';
            $content = file_get_contents($stub);
            $content = str_replace(['{{migrationName}}', '{{table}}'], [$migrationName, $pluralName], $content);
            file_put_contents($modulePath . '/Database/Migrations/' . $file, $content);
        }
    }

    protected function generateFormRequest(string $moduleName, string $modulePath): void
    {
        $requestName = ucfirst($moduleName) . 'Request';
        $stub = $this->resolveStubsPath() . '/Http/Requests/Request.php.stub';
        if (is_file($stub)) {
            $content = file_get_contents($stub);
            $content = str_replace(['{{moduleName}}', '{{requestName}}'], [$moduleName, $requestName], $content);
            file_put_contents($modulePath . '/Http/Requests/' . $requestName . '.php', $content);
        }
    }

    /* ==================== AUTOMATIZACIONES ==================== */

    protected function ensureModulesPsr4Autoload(): void
    {
        $composerPath = base_path('composer.json');
        if (!file_exists($composerPath)) {
            $this->warn('composer.json no encontrado. Omite autoload PSR-4.');
            return;
        }

        $data = json_decode(file_get_contents($composerPath), true) ?: [];
        $changed = false;

        $data['autoload'] = $data['autoload'] ?? [];
        $data['autoload']['psr-4'] = $data['autoload']['psr-4'] ?? [];

        if (!isset($data['autoload']['psr-4']['Modules\\'])) {
            $data['autoload']['psr-4']['Modules\\'] = 'modules/';
            $changed = true;
        }

        if ($changed) {
            file_put_contents($composerPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->info('Added "Modules\\\\": "modules/" to composer.json (autoload.psr-4)');
            $this->runComposerDumpAutoload();
        }
    }

    protected function runComposerDumpAutoload(): void
    {
        $bin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'composer.bat' : 'composer';
        @exec($bin . ' dump-autoload 2>&1', $out, $code);
        if ($code !== 0) {
            $this->warn('No se pudo ejecutar "composer dump-autoload" automáticamente. Ejecútalo manualmente.');
        }
    }

    protected function autoRegisterModuleProvider(string $moduleName): void
    {
        $fqcn = "Modules\\{$moduleName}\\Providers\\{$moduleName}ServiceProvider";
        $major = (int) strtok(app()->version(), '.');

        if ($major >= 11) {
            $this->registerInBootstrapProviders($fqcn);
        } else {
            $this->registerInConfigApp($fqcn);
        }
    }

    protected function registerInBootstrapProviders(string $fqcn): void
    {
        $file = base_path('bootstrap/providers.php');
        if (!file_exists($file)) {
            $this->warn('bootstrap/providers.php no existe; omitiendo registro automático.');
            return;
        }

        $contents = file_get_contents($file);
        if (str_contains($contents, $fqcn . '::class')) {
            $this->line("Provider ya estaba en bootstrap/providers.php: {$fqcn}");
            return;
        }

        $newLine = '    ' . $fqcn . '::class,' . PHP_EOL;
        $updated = preg_replace('/\]\s*;\s*$/m', $newLine . '];', $contents, 1);
        if ($updated && $updated !== $contents) {
            file_put_contents($file, $updated);
            $this->info("Provider registrado en bootstrap/providers.php: {$fqcn}");
        } else {
            $this->warn('No se pudo editar bootstrap/providers.php automáticamente. Agrega el provider manualmente.');
        }
    }

    protected function registerInConfigApp(string $fqcn): void
    {
        $file = config_path('app.php');
        if (!file_exists($file)) {
            $this->warn('config/app.php no existe; omitiendo registro automático.');
            return;
        }

        $contents = file_get_contents($file);
        if (str_contains($contents, $fqcn . '::class')) {
            $this->line("Provider ya estaba en config/app.php: {$fqcn}");
            return;
        }

        $pattern = '/(\'providers\'\s*=>\s*\[)(.*?)(\n\s*\],)/s';
        if (preg_match($pattern, $contents, $m, PREG_OFFSET_CAPTURE)) {
            $insertion = $m[2][0] . PHP_EOL . '        ' . $fqcn . '::class,';
            $updated = substr_replace($contents, $insertion, $m[2][1], strlen($m[2][0]));
            file_put_contents($file, $updated);
            $this->info("Provider registrado en config/app.php: {$fqcn}");
        } else {
            $this->warn('No se pudo localizar el array providers en config/app.php. Agrega el provider manualmente.');
        }
    }
}
