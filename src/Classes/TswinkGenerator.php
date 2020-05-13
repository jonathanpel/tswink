<?php


namespace TsWink\Classes;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use TsWink\Classes\DbToTsTypeConverter;
use TsWink\Classes\Expressions\ClassExpression;
use TsWink\Classes\Expressions\ClassMemberExpression;
use TsWink\Classes\Expressions\ImportExpression;
use TsWink\Classes\Expressions\TypeExpression;
use TsWink\Classes\Expressions\ExpressionStringGenerationOptions;
use TsWink\Classes\Utils\StringUtils;

class TswinkGenerator
{
    /** @var DbToTsTypeConverter */
    private $typeConverter;

    /** @var Table[] */
    protected $tables;

    public function __construct(Connection $dbConnection)
    {
        $this->typeConverter = new TypeConverter;

        $this->schemaManager = $dbConnection->getSchemaManager();
        $this->tables = $this->schemaManager->listTables();
    }

    /** @param string[] $sources*/
    public function generate($sources, string $classesDestination, string $enumsDestination, ExpressionStringGenerationOptions $codeGenerationOptions)
    {
        if (is_array($sources) && count($sources) > 0) {
            foreach ($sources as $enumsPath) {
                $files = scandir($enumsPath);
                foreach ($files as $file) {
                    if (pathinfo(strtolower($file), PATHINFO_EXTENSION) == 'php') {
                        $this->convertFile($enumsPath . "/" . $file, $classesDestination, $enumsDestination, $codeGenerationOptions);
                    }
                }
            }
        }
    }

    public function convertFile(string $filePath, string $classesDestination, string $enumsDestination, ExpressionStringGenerationOptions $codeGenerationOptions)
    {
        if (ClassExpression::tryParse(file_get_contents($filePath), $class)) {
            if ($class->base_class_name == "Enum") {
                $fileName = $enumsDestination;
            } else {
                $fileName = $classesDestination;
            }
            $fileName .= "/" . $class->name . ".ts";
            $this->mergeDatabaseSchema($class);
            $this->mergeNonAutoGeneratedDeclarations($class, $fileName);
            $this->writeFile($fileName, $class->toTypeScript($codeGenerationOptions));
        }
    }

    public function mergeDatabaseSchema(ClassExpression $class)
    {
        if (!$class->hasMember("table") || !is_array($this->tables)) {
            return;
        }
        $table = current(array_filter($this->tables, function ($t) use ($class) {
            return $t->getName() == $class->members["table"]->initial_value;
        }));
        if ($table === false) {
            return;
        }
        foreach ($table->getColumns() as $column) {
            $classMember = new ClassMemberExpression();
            $classMember->name = $column->getName();
            $classMember->type = new TypeExpression();
            $classMember->type->name = $this->typeConverter->convert($column);
            $class->members[$classMember->name] = $classMember;
        }
        foreach ($class->eloquent_relations as $relation) {
            $classMember = new ClassMemberExpression();
            $classMember->name = Str::snake($relation->name);
            $classMember->type = new TypeExpression();
            $classMember->type->name = $relation->target_class_name;
            $classMember->type->is_collection = $relation->type === HasMany::class || $relation->type === BelongsToMany::class;
            if ($relation->target_class_name != $class->name) {
                $tsImport = new ImportExpression();
                $tsImport->name = $relation->target_class_name;
                $tsImport->target = "./" . $relation->target_class_name;
                $class->imports[$tsImport->name] = $tsImport;
            }
            $class->members[$classMember->name] = $classMember;
        }
    }

    private function mergeNonAutoGeneratedDeclarations(ClassExpression $class, $filePath)
    {
        if (!file_exists($filePath)) {
            return;
        }
        $fileContent = file_get_contents($filePath);
        $class->non_auto_generated_imports = trim(StringUtils::textBetween($fileContent, "// <non-auto-generated-import-declarations>", "// </non-auto-generated-import-declarations>"), "\n");
        $class->non_auto_generated_class_declarations = trim(StringUtils::textBetween($fileContent, "// <non-auto-generated-class-declarations>", "// </non-auto-generated-class-declarations>"), "\n");
    }

    private function writeFile($filePath, $content)
    {
        if (!file_exists(dirname($filePath))) {
            mkdir(dirname($filePath), 077, true);
        }
        file_put_contents($filePath, $content);
    }
}
