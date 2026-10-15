<?php

declare(strict_types=1);

use App\Providers\NamesEssentialsServiceProvider;
use Database\Factories\Faker\AlgerianPerson;
use Faker\Factory;
use Faker\Generator;
use GtsMeghni\EssentialsKit\Installers\UserFactoryPatcher;
use Illuminate\Filesystem\Filesystem;

/**
 * These tests exercise the generated names provider and its wiring into Faker,
 * since a factory calling an unregistered formatter only fails once seeding runs.
 */
beforeEach(function (): void {
    $this->appPath = sys_get_temp_dir().'/essentials-kit-names-'.bin2hex(random_bytes(6));

    $files = new Filesystem;
    $files->ensureDirectoryExists($this->appPath.'/app/Providers');
    $files->ensureDirectoryExists($this->appPath.'/database/factories');
    $files->put($this->appPath.'/database/factories/UserFactory.php', <<<'PHP'
<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
        ];
    }
}

PHP);

    $this->app->setBasePath($this->appPath);

    $this->artisan('essentials:install', ['--features' => ['names'], '--no-interaction' => true])->assertSuccessful();

    if (! class_exists(AlgerianPerson::class)) {
        require $this->appPath.'/database/factories/Faker/AlgerianPerson.php';
    }

    // Other tests load a generated EssentialsServiceProvider built from other
    // features, so this one is loaded under its own name to avoid the clash.
    if (! class_exists(NamesEssentialsServiceProvider::class)) {
        $renamed = $this->appPath.'/app/Providers/NamesEssentialsServiceProvider.php';

        $files->put($renamed, str_replace(
            'class EssentialsServiceProvider',
            'class NamesEssentialsServiceProvider',
            $files->get($this->appPath.'/app/Providers/EssentialsServiceProvider.php'),
        ));

        require $renamed;
    }
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->appPath);
});

function algerianFaker(): Generator
{
    $faker = Factory::create();
    $faker->addProvider(new AlgerianPerson($faker));

    return $faker;
}

it('writes the names provider and boots it from the generated provider', function (): void {
    expect((new Filesystem)->exists($this->appPath.'/database/factories/Faker/AlgerianPerson.php'))->toBeTrue()
        ->and((new Filesystem)->get($this->appPath.'/app/Providers/EssentialsServiceProvider.php'))
        ->toContain('$this->bootNames();')
        ->toContain('use Database\Factories\Faker\AlgerianPerson;')
        ->toContain('if (! class_exists(Generator::class)) {');
});

it('points the user factory at algerian names', function (): void {
    expect((new Filesystem)->get($this->appPath.'/database/factories/UserFactory.php'))
        ->toContain("'name' => fake()->algerianName(),")
        ->toContain("'email' => fake()->unique()->safeEmail(),");
});

it('registers the formatters on every faker the container resolves', function (): void {
    $this->app->register(NamesEssentialsServiceProvider::class);

    expect(fake()->algerianName())->toMatch('/^\S.* \S.*$/')
        ->and(fake('fr_FR')->algerianLastName())->toBeString()->not->toBeEmpty()
        ->and(fake()->unique()->algerianFirstName('female'))->toBeString();
});

it('keeps first names apart by gender', function (): void {
    $faker = algerianFaker();

    $male = array_map(fn (): string => $faker->algerianFirstName('male'), range(1, 200));
    $female = array_map(fn (): string => $faker->algerianFirstName('female'), range(1, 200));

    expect($male)->toContain('Mohamed')->not->toContain('Meriem')
        ->and($female)->toContain('Meriem')->not->toContain('Mohamed');
});

it('refuses an unknown gender', function (): void {
    algerianFaker()->algerianFirstName('other');
})->throws(InvalidArgumentException::class);

it('follows the faker seed so seeders are reproducible', function (): void {
    $faker = algerianFaker();

    $faker->seed(42);
    $first = [$faker->algerianName(), $faker->algerianMobile()];

    $faker->seed(42);
    $second = [$faker->algerianName(), $faker->algerianMobile()];

    expect($first)->toBe($second);
});

it('generates mobile numbers the phone rule accepts', function (): void {
    $faker = algerianFaker();

    foreach (range(1, 50) as $ignored) {
        expect($faker->algerianMobile())->toMatch('/^(\+213|0)(5|6|7)[0-9]{8}$/');
    }
});

it('patches the user factory once and explains an unexpected shape', function (): void {
    $patcher = new UserFactoryPatcher(new Filesystem, $this->appPath);
    $path = $this->appPath.'/database/factories/UserFactory.php';
    $before = (new Filesystem)->get($path);

    expect($patcher->patch())->toBeNull()
        ->and((new Filesystem)->get($path))->toBe($before);

    (new Filesystem)->put($path, "<?php\n\nreturn ['name' => 'Fixed'];\n");

    expect($patcher->patch())->toContain('not in the expected shape');

    (new Filesystem)->delete($path);

    expect($patcher->patch())->toContain('was not found');
});

it('leaves out the surnames of political figures', function (): void {
    $surnames = (new ReflectionClass(AlgerianPerson::class))->getConstant('LAST');

    expect($surnames)->not->toContain(
        'Tebboune', 'Bouteflika', 'Zeroual', 'Boudiaf', 'Boumediene', 'Chadli', 'Ben Bella', 'Kafi',
        'Bensalah', 'Ouyahia', 'Sellal', 'Benflis', 'Hamrouche', 'Bedoui', 'Djerad', 'Benabderrahmane',
        'Larbaoui', 'Ait Ahmed', 'Belkheir', 'Khelil', 'Hamel', 'Chengriha', 'Gaid Salah',
    );
});
