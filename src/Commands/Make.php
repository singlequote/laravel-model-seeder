<?php
namespace SingleQuote\ModelSeeder\Commands;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use File;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection as Collection2;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Finder\SplFileInfo;
use Throwable;
use function base_path;
use function config;
use function str;

class Make extends Command
{

    /**
     * @var  string
     */
    protected $signature = 'seed:make {--path=} {--output=auto} {--with-events} {--only=} {--orderBy=} {--orderByDesc=}';

    /**
     * @var  string
     */
    protected $description = 'Create seeders from your models using the database';

    /**
     * @var array
     */
    protected array $models = [];

    /**
     * @var array
     */
    protected array $config = [];

    /**
     * @var array
     */
    protected array $relations = [];

    /**
     * @var string|null
     */
    protected ?string $outputPath;

    /**
     * 
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * 
     */
    public function handle()
    {
        $this->outputPath = base_path(config('model-seeder.output_path', 'database/seeder'));

        if ($this->option('output') && $this->option('output') !== 'auto') {
            $this->outputPath = base_path($this->option('output'));
        }

        if ($this->option('output') && $this->option('output') === 'auto') {
            $this->outputPath = null;
        }

        if ($this->option('output') !== 'auto' && !File::isDirectory($this->outputPath)) {
            File::makeDirectory($this->outputPath);
        }

        $this->extractModels();

        $this->createModelSeeders();

        $this->createDatabaseSeeders();

        $this->info("Database seeders created!");
    }

    /**
     * @return void
     */
    private function createDatabaseSeeders(): void
    {
        $stubFile = __DIR__ . "/../stubs/database.stub";

        $grouped = [];

        foreach ($this->models as $model) {
            $path = $this->outputPath ?? $this->findOutputPath($model);
            $namespace = $this->parseNamespace($path);
            $grouped[$namespace][] = $model;
        }

        foreach ($grouped as $namespace => $group) {
            $content = str(File::get($stubFile));
            $replaced = "";

            foreach ($group as $model) {
                $path = $this->outputPath ?? $this->findOutputPath($model);
                $replaced .= "            {$model['model']}Seeder::class,\n";
            }

            $fileContent = $content->replace("<namespace>", $namespace)
                ->replace("<lines>", $replaced);

            File::put("$path/DatabaseSeeder.php", $fileContent);
        }
    }

    /**
     * @param string $path
     * @return string
     */
    private function parseNamespace(string $path): string
    {
        $keys = str($path)
            ->replace(['/', '\\'], '<>')
            ->explode('<>');

        $baseKeys = str(base_path())
            ->replace(['/', '\\'], '<>')
            ->explode('<>');

        $basePath = $this->pathToNameSpace($baseKeys);
        $namespace = $this->pathToNameSpace($keys);

        return str($namespace)->after("$basePath\\")->toString();
    }

    /**
     * @param Collection2 $keys
     * @return string
     */
    private function pathToNameSpace(Collection2 $keys): string
    {
        $namespace = "";

        foreach ($keys as $key) {

            if (strlen($key) < 3) {
                continue;
            }

            if (in_array($key, ['Models', 'Entities'])) {
                break;
            }

            $namespace .= ucFirst($key) . "\\";
        }

        return rtrim($namespace, "\\");
    }

    /**
     * @return void
     */
    private function createModelSeeders(): void
    {
        foreach ($this->models as $model) {

            $className = $model['namespace'] . "\\" . $model['model'];

            try {
                $this->extractModelData($model, new $className);
            } catch (Throwable $ex) {
                $this->info($ex);
                $this->error("$className is name a valid model");
                exit;
            }
        }
    }

    /**
     * @param array $config
     * @param Model $model
     * @return void
     */
    private function extractModelData(array $config, Model $model): void
    {
        try {
            $query = $model::withoutGlobalScopes();
            
            if($this->option('orderBy')){
                $query->orderBy($this->option('orderBy'));
            }
            if($this->option('orderByDesc')){
                $query->orderByDesc($this->option('orderByDesc'));
            }
            
            $data = $query->get();
            
        } catch (Throwable $ex) {
            $this->error("Failed to parse {$config['model']}");
            $this->error($ex->getMessage());
            return;
        }

        $this->parseSeederFile($model, $config, $data);
    }

    /**
     * @param Model $model
     * @param array $config
     * @param Collection $data
     * @return void
     */
    private function parseSeederFile(Model $model, array $config, Collection $data): void
    {
        $this->config = $config;
        
        $stubFile = __DIR__ . "/../stubs/seeder.stub";

        $content = str(File::get($stubFile));

        $path = $this->outputPath ?? $this->findOutputPath($config);
        $namespace = $this->parseNamespace($path);

        $replaced = $content->replace("<model>", $config['model'])
            ->replace("<parentNamespace>", $namespace)
            ->replace("<namespace>", $config['namespace'])
            ->replace("<modelEvents>", $this->option('with-events') ? "" : "use WithoutModelEvents;\n")
            ->replace("<lines>", $this->stubModelLines($model, $config, $data));

        File::put("$path/{$config['model']}Seeder.php", $replaced);
    }

    /**
     * @param array $config
     * @param int $retries
     * @param string|null $relativePath
     * @return string
     */
    private function findOutputPath(array $config, int $retries = 0, ?string $relativePath = null): string
    {
        $path = $relativePath ?? $config['relativePath'];

        if ($retries > 10) {
            $this->error("oops, output path not found at $path");
            exit;
        }

        $directories = File::directories("$path/..");

        foreach ($directories as $directory) {
            if (str($directory)->after("$path/..")->lower()->slug()->toString() !== "database") {
                continue;
            }

            $name = str($directory)
                ->replace(['/', '\\'], '<>')
                ->afterLast('<>')
                ->toString();

            if ($name[0] === 'D') {
                $seederPath = "Seeders";
            } else {
                $seederPath = "seeders";
            }

            if (!File::isDirectory("$directory/$seederPath")) {
                File::makeDirectory("$directory/$seederPath");
            }

            return $this->parseRelativePath("$directory/$seederPath");
        }

        return $this->findOutputPath($config, $retries + 1, "$path/../");
    }

    /**
     * @param string $path
     * @return string
     */
    private function parseRelativePath(string $path): string
    {
        $relative = str($path)->replace(['\\', '/'], '<>')->replace('<><>', '<>')->explode('<>');

        $namespace = "";
        $prev = [];
        $prevIndex = -1;

        foreach ($relative as $key) {

            if ($key === '..') {
                $namespace = str($namespace)->beforeLast("{$prev[$prevIndex]}/")->toString();
                $prevIndex--;
                continue;
            }

            $namespace .= "$key/";
            $prev[] = $key;
            $prevIndex++;
        }

        return rtrim($namespace, "/");
    }

    /**
     * @param Model $model
     * @param array $config
     * @param Collection $data
     * @return string
     */
    private function stubModelLines(Model $model, array $config, Collection $data): string
    {
        $stubFile = __DIR__ . "/../stubs/model.stub";

        $content = str(File::get($stubFile));

        $replaced = "";
        
        foreach ($data as $index => $line) {
            $replaced .= str($content)->replace("<model>", $config['model'])
                ->replace("<index>", $index)
                ->replace("<lines>", $this->stubModelLine($model, $config, $line));
        }
        
        $relations = $this->getPivotRelations($model);
        
        
        foreach($relations as $relation){
            
            if($this->confirm("Create pivot seeder for ".$model::class."->$relation()?")){
                $this->createPivotSeeder($model, $data, $relation);
            }
            
        }
        
        return $replaced;
    }
    
    /**
     * @param Model $model
     * @param Collection $data
     * @param string $relation
     * @return void
     */
    private function createPivotSeeder(Model $model, Collection $data, string $relation): void
    {
        $stubFile = __DIR__ . "/../stubs/seeder.stub";
        
        $content = str(File::get($stubFile));

        $path = $this->outputPath ?? $this->findOutputPath($this->config);
        $namespace = $this->parseNamespace($path);
        $fileName = str($model->$relation()->getTable())->append("_pivot")->studly();
                
        $replaced = $content->replace("<namespace>\<model>", "Illuminate\Support\Facades\DB")
            ->replace("<model>", $fileName)
            ->replace("<parentNamespace>", $namespace)
            ->replace("<namespace>", $this->config['namespace'])
            ->replace("<modelEvents>", $this->option('with-events') ? "" : "use WithoutModelEvents;\n")
            ->replace("<lines>", $this->stubPivotRelations($model, $data, $relation));
                
        File::put("$path/{$fileName}Seeder.php", $replaced);
    }
    

    /**
     * @param Model $model
     * @param int $index
     * @return string
     */
    private function stubPivotRelations(Model $model, Collection $items, string $relation): string
    {
        $stubFile = __DIR__ . "/../stubs/pivot-relations.stub";

        $content = str(File::get($stubFile));

        $replaced = "";
        
        $connection = $model->$relation()->getConnection()->getName();
        
        foreach ($items as $item) {            
            if (!$item->$relation->count()) {
                continue;
            }

            foreach ($item->$relation as $data) {
                $replaced .= str($content)->replace("<connection>", $connection)
                    ->replace("<table>", $model->$relation()->getTable())
                    ->replace("<lines>", $this->getPivotValues($data));
            }
                        
            $this->relations[$model->$relation()->getTable()] = true;
        }

        return $replaced;
    }
    
    /**
     * @param Model $data
     * @return string
     */
    private function getPivotValues(Model $data): string
    {
        $replaced = "";

        $stubFile = __DIR__ . "/../stubs/pivot-relation-lines.stub";
        $content = str(File::get($stubFile));

        foreach ($data->pivot->getAttributes() as $key => $value) {
            $type = $this->parseValueType($value);
            $replaced .= str($content)->replace("<key>", $key)->replace("<value>", $type)->replace(',,', ',');
        }

        return $replaced;
    }

    /**
     * @param Model $model
     * @return array
     */
    private function getPivotRelations(Model $model): array
    {
        $reflectionClass = new ReflectionClass($model);

        $relations = [];

        foreach ($reflectionClass->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $methodName = $method->getName();

            $returnType = $reflectionClass->getMethod($methodName)->getReturnType();

            if (!$returnType || (string) $returnType !== BelongsToMany::class) {
                continue;
            }

            $relations[$methodName] = $methodName;
        }

        return $relations;
    }

    /**
     * @param Model $model
     * @param array $config
     * @param Model $line
     * @return string
     */
    private function stubModelLine(Model $model, array $config, Model $line): string
    {
        $stubFile = __DIR__ . "/../stubs/line.stub";

        $content = str(File::get($stubFile));

        $replaced = "";

        foreach ($line->getAttributes() as $key => $preValue) {
            if (in_array($key, config('model-seeder.exclude_columns', []))) {
                continue;
            }

            $value = $line->$key;

            $valueType = $this->parseValueType($value, $preValue);

            try {
                $replaced .= str($content)->replace("<key>", $key)->replace("<value>", $valueType)->replace(',,', ',');
            } catch (Throwable $ex) {
                $this->error($ex);
                exit;
            }
        }

        return $replaced;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function parseValueType(mixed $value, mixed $preValue = null): mixed
    {        
        if ($value instanceof Carbon || $value instanceof CarbonImmutable) {
            return "'$preValue'";
        }

        if (is_object($value)) {
            return $this->stringableArray($value);
        }

        if (is_array($value)) {
            return $this->stringableArray($value);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_integer($value)) {
            return $value;
        }

        if (is_null($value)) {
            return "null";
        }

        return "'" . addslashes($value) . "'";
    }

    /**
     * @param array|object $items
     * @return string
     */
    private function stringableArray(array|object $items): string
    {
        $string = "[";

        foreach ($items as $key => $value) {

            if (is_array($value) || is_object($value)) {
                $string .= $this->stringableArray($value);
            } else {

                $parsedValue = $this->parseValueType($value);

                $string .= "\"$key\" => $parsedValue, ";
            }
        }

        return "$string],";
    }

    /**
     * @return void
     */
    private function extractModels(): void
    {
        $paths = config('model-seeder.models_path', []);

        if ($this->option('path') && $this->option('path') !== 'auto') {
            $paths = [$this->option('path')];
        }

        if ($this->option('path') && $this->option('path') === 'auto') {
            $paths = $this->extractDeclaredClasses();
        }

        foreach ($paths as $modelPath) {
            $models = File::allFiles(base_path($modelPath));

            foreach ($models as $file) {
                $this->models[] = $this->extractClassInfo($file);
            }
        }

        $this->filterModels();
    }

    /**
     * @return void
     */
    private function filterModels(): void
    {
        foreach ($this->models as $index => $model) {
            if ($this->option('only') && !str($this->option('only'))->contains($model['model'])) {
                unset($this->models[$index]);
            }
        }
    }

    /**
     * @param array $paths
     * @return array
     */
    private function extractDeclaredClasses(array $paths = []): array
    {
        foreach (get_declared_classes() as $class) {

            $reflector = new \ReflectionClass($class);
            $path = str($reflector->getFileName())->after(base_path())->trim('/\\');

            if (is_subclass_of($class, Model::class) && !$path->contains('vendor')) {
                $parsed = $this->parseRelativePath($path->replace('.php', '') . "/../");
                $paths[$parsed] = $parsed;
            }
        }

        return $paths;
    }

    /**
     * @param SplFileInfo $file
     * @return array
     */
    private function extractClassInfo(SplFileInfo $file): array
    {
        $data = [
            'basePath' => $file->getPathname(),
            'relativePath' => $file->getPath(),
        ];
        $content = $file->getContents();

        $lines = preg_split('/\r\n|\r|\n/', $content);

        foreach ($lines as $line) {

            if (str($line)->startsWith('namespace')) {
                $data['namespace'] = str($line)->after('namespace ')->before(';')->toString();
            }
            if (str($line)->startsWith('class')) {
                $data['model'] = str($line)->after('class ')->before(' ')->toString();
            }
            if (str($line)->startsWith('final class')) {
                $data['model'] = str($line)->after('final class ')->before(' ')->toString();
            }
        }


        return $data;
    }
}
