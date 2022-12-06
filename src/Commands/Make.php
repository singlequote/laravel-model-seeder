<?php
namespace SingleQuote\ModelSeeder\Commands;

use DB;
use File;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
    protected $signature = 'seed:make {--path=} {--output=auto} {--with-events}';

    /**
     * @var  string
     */
    protected $description = 'Create seeders from your models using the database';

    /**
     * @var array
     */
    protected array $models = [];

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

            $fileContent = $content->replace("<namespace>", "$namespace\Database\Seeders")->replace("<lines>", $replaced);

            File::put("$path/DatabaseSeeder.php", $fileContent);
        }
    }

    /**
     * @param string $path
     * @return string
     */
    private function parseNamespace(string $path): string
    {
        $parsed = str($path)->after(base_path())
            ->replace(['/', '\\'], '-')
            ->explode('-');

        $namespace = "";

        foreach ($parsed as $key) {

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
            $data = $model::withoutGlobalScopes()->get();
        } catch (Throwable $ex) {
            $this->error("Failed to parse {$config['model']}");
            dd($ex);
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
        $stubFile = __DIR__ . "/../stubs/seeder.stub";
        
        $content = str(File::get($stubFile));

        $replaced = $content->replace("<model>", $config['model'])
            ->replace("<namespace>", $config['namespace'])
            ->replace("<modelEvents>", $this->option('with-events') ? "" : "use WithoutModelEvents;\n")
            ->replace("<lines>", $this->stubModelLines($model, $config, $data));

        $path = $this->outputPath ?? $this->findOutputPath($config);

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
                ->replace(['/', '\\'], '-')
                ->afterLast('-')
                ->toString();

            if ($name[0] === 'D') {
                $seederPath = "Seeders";
            } else {
                $seederPath = "seeders";
            }

            if (!File::isDirectory("$directory/$seederPath")) {
                File::makeDirectory("$directory/$seederPath");
            }

            return "$directory/$seederPath";
        }

        return $this->findOutputPath($config, $retries + 1, "$path/../");
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

        foreach ($data as $line) {
            $replaced .= str($content)->replace("<model>", $config['model'])
                ->replace("<lines>", $this->stubModelLine($model, $config, $line))
                ->replace("<pivotRelations>", $this->stubPivotRelations($line));
        }

        return $replaced;
    }
    
    private function stubPivotRelations(Model $model)
    {
        $relations = $this->getPivotRelations($model);
        
        $stubFile = __DIR__ . "/../stubs/pivot-relations.stub";
        
        $content = str(File::get($stubFile));
                
        $replaced = "";
        
        foreach($relations as $relation){
            
            if(!$model->$relation->count()){
                continue;
            }
            
            $connection = $model->$relation()->getConnection()->getName();
            
            $replaced .= str($content)->replace("<connection>", $connection)
                ->replace("<relation>", $relation)
                ->replace("<table>", $model->$relation()->getTable())
                ->replace("<lines>", $this->parsePivotRelationLines($model->$relation));        
        }
        
        return $replaced;
    }
    
    private function parsePivotRelationLines(Collection $data)
    {
        $stubFile = __DIR__ . "/../stubs/pivot-relation-lines.stub";
        
        $content = str(File::get($stubFile));

        $replaced = "";

        foreach ($data as $pivot) {
            foreach($pivot->pivot->getAttributes() as $key => $value){
                if (in_array($key, config('model-seeder.exclude_columns', []))) {
                    continue;
                }

                $valueType = $this->parseValueType($value);

                $replaced .= str($content)->replace("<key>", $key)->replace("<value>", $valueType);
            }
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

        foreach ($line->getAttributes() as $key => $value) {
            if (in_array($key, config('model-seeder.exclude_columns', []))) {
                continue;
            }

            $valueType = $this->parseValueType($value);

            try {
                $replaced .= str($content)->replace("<key>", $key)->replace("<value>", $valueType);
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
    private function parseValueType(mixed $value): mixed
    {
        if (is_object($value) || is_array($value)) {
            $value = json_encode($value);
        }

        if (is_integer($value)) {
            return $value;
        }

        if (is_null($value)) {
            return "null";
        }

        return "'$value'";
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
            dd('Not yet supported!');
        }

        foreach ($paths as $modelPath) {
            $models = File::allFiles(base_path($modelPath));

            foreach ($models as $file) {
                $this->models[] = $this->extractClassInfo($file);
            }
        }
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
