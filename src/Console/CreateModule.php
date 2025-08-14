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

        // Ruta destino configurable (config/module-maker.php)
        $modulesRoot = config('module-maker.modules_path', base_path('modules'));
        $modulePath  = rtrim($modulesRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $moduleName;

        if (File::exists($modulePath)) {
            $this->error('Module already exists!');
            return;
        }

        File::makeDirectory($modulePath, 0755, true);

        $this->generateFolders($moduleName, $modulePath);
        $this->generateFiles($moduleName, $modulePath);

        // Asegurar autoload PSR-4 y registrar el provider del módulo
        $this->ensureModulesPsr4Autoload();
        $this->autoRegisterModuleProvider($moduleName);

        $this->info("Module {$moduleName} created successfully!");
    }

    /**********************
     * GENERACIÓN DE ARCHIVOS
     **********************/
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
        // 1) Si el proyecto publicó stubs, úsalos
        $published = config('module-maker.stubs_path', base_path('stubs/vendor/module-maker/module'));
        if (File::isDirectory($published)) {
            return $published;
        }

        // 2) Fallback: usa los stubs internos del paquete (src/stubs/module)
        return __DIR__ . '/../stubs/module';
    }

    protected function generateFiles(string $moduleName, string $modulePath): void
    {
        $stubPath = $this->resolveStubsPath();

        // Copiamos todos los stubs al destino
        File::copyDirectory($stubPath, $modulePath);

        // Estos stubs "semilla" NO se renombran aquí, porque luego
        // generateModel/Controller/Migration/Request crean sus archivos definitivos.
        $skipRename = [
            'Models' . DIRECTORY_SEPARATOR . 'modelName.php.stub',
            'Http' . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . 'controllerName.php.stub',
            'Database' . DIRECTORY_SEPARATOR . 'Migrations' . DIRECTORY_SEPARATOR . 'migration.php.stub',
            'Http' . DIRECTORY_SEPARATOR . 'Requests' . DIRECTORY_SEPARATOR . 'Request.php.stub',
        ];

        $files = File::allFiles($modulePath);

        foreach ($files as $file) {
            $rel = $file->getRelativePathname(); // con separadores nativos
            $relNorm = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel);

            // Saltar los stubs “semilla”
            if (in_array($relNorm, $skipRename, true)) {
                continue;
            }

            $origPath = $file->getPathname();

            // Nuevo nombre de archivo: reemplazar {{moduleName}} y quitar .stub
            $newFileName = str_replace(['{{moduleName}}', '.stub'], [$moduleName, ''], $file->getFilename());
            $targetPath = $modulePath . DIRECTORY_SEPARATOR . $file->getRelativePath() . DIRECTORY_SEPARATOR . $newFileName;

            // Reemplazo de contenido
            $contents = file_get_contents($origPath);
            if (is_string($contents)) {
                $contents = str_replace('{{moduleName}}', $moduleName, $contents);
            } else {
                $contents = '';
            }

            // Escribir destino de forma segura (evita locks en Windows) y borrar el original
            $this->safeReplaceFile($targetPath, $contents);
            @unlink($origPath);
        }

        // Artefactos que dependen de sus stubs “semilla”
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

        // Si existe destino, intentar borrarlo (con reintento) por si hay locks en Windows
        for ($i = 0; $i < $retries; $i++) {
            if (!file_exists($targetPath) || @unlink($targetPath)) {
                break;
            }
            usleep($sleepMs * 1000);
        }

        // Escribir a archivo temporal y luego renombrar (atomic-ish)
        $tmp = $targetPath . '.tmp' . bin2hex(random_bytes(4));
        file_put_contents($tmp, $contents);

        for ($i = 0; $i < $retries; $i++) {
            if (@rename($tmp, $targetPath)) {
                return;
            }
            usleep($sleepMs * 1000);
        }

        // Último intento: copiar y borrar tmp
        @copy($tmp, $targetPath);
        @unlink($tmp);
    }

    protected function generateModel(string $moduleName, string $modulePath): void
    {
        $modelName = ucfirst($moduleName);

        $modelStubPath = $this->resolveStubsPath() . '/Models/modelName.php.stub';
        $modelStubContent = file_get_contents($modelStubPath);
        $modelContent = str_replace('{{modelName}}', $modelName, $modelStubContent);
        $modelContent = str_replace('{{moduleName}}', $moduleName, $modelContent);

        $modelPath = $modulePath . '/Models/' . $modelName . '.php';
        file_put_contents($modelPath, $modelContent);

        // Limpieza por si el stub quedó copiado
        $extraModelPath = $modulePath . '/Models/modelName.php';
        if (file_exists($extraModelPath)) {
            @unlink($extraModelPath);
        }
    }

    protected function generateController(string $moduleName, string $modulePath): void
    {
        $controllerName = ucfirst($moduleName) . 'Controller';

        $controllerStubPath = $this->resolveStubsPath() . '/Http/Controllers/controllerName.php.stub';
        $controllerStubContent = file_get_contents($controllerStubPath);
        $controllerContent = str_replace('{{controllerName}}', $controllerName, $controllerStubContent);
        $controllerContent = str_replace('{{moduleName}}', $moduleName, $controllerContent);

        $controllerPath = $modulePath . '/Http/Controllers/' . $controllerName . '.php';
        file_put_contents($controllerPath, $controllerContent);

        // Limpieza por si el stub quedó copiado
        $extraControllerPath = $modulePath . '/Http/Controllers/controllerName.php';
        if (file_exists($extraControllerPath)) {
            @unlink($extraControllerPath);
        }
    }

    protected function generateMigration(string $moduleName, string $modulePath): void
    {
        $singularName = Str::snake($moduleName);
        $inflector = InflectorFactory::create()->build();
        $pluralName = $inflector->pluralize($singularName);
        $migrationName = 'create_' . $pluralName . '_table';

        $migrationStubPath = $this->resolveStubsPath() . '/Database/Migrations/migration.php.stub';
        $migrationStubContent = file_get_contents($migrationStubPath);

        $timestamp = date('Y_m_d_His');
        $migrationFileName = $timestamp . '_' . $migrationName . '.php';

        $migrationContent = str_replace(
            ['{{migrationName}}', '{{table}}'],
            [$migrationName, $pluralName],
            $migrationStubContent
        );

        $migrationPath = $modulePath . '/Database/Migrations/' . $migrationFileName;
        file_put_contents($migrationPath, $migrationContent);

        // Limpieza por si el stub quedó copiado
        $extraMigrationPath = $modulePath . '/Database/Migrations/migration.php';
        if (file_exists($extraMigrationPath)) {
            @unlink($extraMigrationPath);
        }
    }

    protected function generateFormRequest(string $moduleName, string $modulePath): void
    {
        $requestName = ucfirst($moduleName) . 'Request';

        $requestStubPath = $this->resolveStubsPath() . '/Http/Requests/Request.php.stub';
        $requestStubContent = file_get_contents($requestStubPath);
        $requestContent = str_replace(['{{moduleName}}', '{{requestName}}'], [$moduleName, $requestName], $requestStubContent);

        $requestPath = $modulePath . '/Http/Requests/' . $requestName . '.php';
        file_put_contents($requestPath, $requestContent);

        // Limpieza por si el stub quedó copiado
        $requestFilePath = $modulePath . '/Http/Requests/Request.php';
        if (file_exists($requestFilePath)) {
            @unlink($requestFilePath);
        }
    }

    /**********************
     * AUTOMATIZACIONES
     **********************/

    /** Asegura "Modules\\" => "modules/" en composer.json y corre dump-autoload si hubo cambios */
    protected function ensureModulesPsr4Autoload(): void
    {
        $composerPath = base_path('composer.json');
        if (!file_exists($composerPath)) {
            $this->warn('composer.json no encontrado. Omite autoload PSR-4.');
            return;
        }

        $data = json_decode(file_get_contents($composerPath), true) ?: [];
        $changed = false;

        if (!isset($data['autoload']['psr-4'])) {
            $data['autoload']['psr-4'] = [];
            $changed = true;
        }

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

    /** Intenta ejecutar composer dump-autoload (Windows/Linux/Mac) */
    protected function runComposerDumpAutoload(): void
    {
        $bin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'composer.bat' : 'composer';
        $cmd = $bin . ' dump-autoload';
        try {
            @exec($cmd . ' 2>&1', $output, $code);
            if ($code === 0) {
                $this->line(implode(PHP_EOL, $output));
            } else {
                $this->warn('No se pudo ejecutar "composer dump-autoload" automáticamente. Ejecútalo manualmente.');
            }
        } catch (\Throwable $e) {
            $this->warn('No se pudo ejecutar "composer dump-autoload". Ejecútalo manualmente.');
        }
    }

    /** Registra el provider del módulo en bootstrap/providers.php (L11/L12) o config/app.php (L10) */
    protected function autoRegisterModuleProvider(string $moduleName): void
    {
        $fqcn = "Modules\\{$moduleName}\\Providers\\{$moduleName}ServiceProvider";

        // Detecta versión mayor de Laravel
        $ver = app()->version();           // ej. "12.3.0"
        $major = (int) strtok($ver, '.');  // 12

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

        // Ya está registrado?
        if (str_contains($contents, $fqcn . '::class')) {
            $this->line("Provider ya estaba en bootstrap/providers.php: {$fqcn}");
            return;
        }

        // providers.php retorna un array. Insertar línea antes del cierre '];'
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

        // Ya está?
        if (str_contains($contents, $fqcn . '::class')) {
            $this->line("Provider ya estaba en config/app.php: {$fqcn}");
            return;
        }

        // Buscar el array 'providers' y añadir el FQCN antes del cierre.
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
