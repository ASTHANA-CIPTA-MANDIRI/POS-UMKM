<?php

namespace Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Memanggil setiap method relasi di setiap model untuk menangkap salah nama kelas,
 * foreign key, atau tabel pivot — kesalahan yang tidak terdeteksi php -l.
 */
class ModelRelationsSmokeTest extends TestCase
{
    public function test_semua_relasi_model_bisa_dibangun(): void
    {
        $models = $this->modelClasses();

        $this->assertGreaterThanOrEqual(25, count($models), 'Model tidak terdeteksi.');

        $diperiksa = 0;

        foreach ($models as $class) {
            $model = new $class;

            foreach ($this->relationMethods($class) as $method) {
                $relation = $model->{$method}();

                $this->assertInstanceOf(
                    Relation::class,
                    $relation,
                    "{$class}::{$method}() tidak mengembalikan Relation.",
                );

                // Memaksa query dirakit; foreign key / pivot yang salah muncul di sini.
                $this->assertNotEmpty(
                    $relation->toSql(),
                    "{$class}::{$method}() gagal merakit query.",
                );

                $diperiksa++;
            }
        }

        $this->assertGreaterThanOrEqual(60, $diperiksa, 'Relasi yang diperiksa terlalu sedikit.');
    }

    /** @return array<int, class-string<Model>> */
    private function modelClasses(): array
    {
        $classes = [];

        foreach (Finder::create()->files()->in(app_path('Models'))->name('*.php')->depth(0) as $file) {
            /** @var SplFileInfo $file */
            $class = 'App\\Models\\'.Str::before($file->getFilename(), '.php');

            if (is_subclass_of($class, Model::class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /** @return array<int, string> */
    private function relationMethods(string $class): array
    {
        $methods = [];

        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->class !== $class || $method->getNumberOfRequiredParameters() > 0) {
                continue;
            }

            $type = $method->getReturnType();

            if ($type instanceof ReflectionNamedType
                && ! $type->isBuiltin()
                && is_subclass_of($type->getName(), Relation::class)) {
                $methods[] = $method->getName();
            }
        }

        return $methods;
    }
}
