<?php

declare(strict_types=1);

namespace GlpiPlugin\Experiencekit\Infrastructure\Builder\Support;

/**
 * Seeded, dependency-free random data (no Faker in this codebase's vendor
 * tree, and adding one just for this would be a new Composer dependency
 * for a plugin). Every method takes an explicit $sequence rather than
 * relying on ambient RNG state, so the Nth generated record is always the
 * same regardless of which batch/process created it - true reproducibility
 * per-unit, not just "the same script produces the same run" like the
 * original generation scripts (§7.7).
 */
final class RandomDataProvider
{
    private const FIRST_NAMES = [
        'James', 'Mary', 'Robert', 'Patricia', 'John', 'Jennifer', 'Michael', 'Linda',
        'David', 'Elizabeth', 'William', 'Barbara', 'Richard', 'Susan', 'Joseph', 'Jessica',
        'Thomas', 'Sarah', 'Charles', 'Karen', 'Christopher', 'Nancy', 'Daniel', 'Lisa',
        'Matthew', 'Betty', 'Anthony', 'Margaret', 'Mark', 'Sandra', 'Donald', 'Ashley',
        'Steven', 'Kimberly', 'Andrew', 'Emily', 'Paul', 'Donna', 'Joshua', 'Michelle',
        'Kenneth', 'Dorothy', 'Kevin', 'Carol', 'Brian', 'Amanda', 'George', 'Melissa',
        'Edward', 'Deborah', 'Ronald', 'Stephanie', 'Timothy', 'Rebecca', 'Jason', 'Sharon',
        'Jeffrey', 'Laura', 'Ryan', 'Cynthia', 'Jacob', 'Kathleen', 'Gary', 'Amy',
        'Nicholas', 'Angela', 'Eric', 'Shirley', 'Jonathan', 'Anna', 'Stephen', 'Brenda',
        'Larry', 'Pamela', 'Justin', 'Emma', 'Scott', 'Nicole', 'Brandon', 'Helen',
        'Amit', 'Priya', 'Wei', 'Yuki', 'Chen', 'Fatima', 'Omar', 'Aisha',
        'Carlos', 'Sofia', 'Luca', 'Marta', 'Hans', 'Ingrid', 'Pierre', 'Claire',
    ];

    private const LAST_NAMES = [
        'Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis',
        'Rodriguez', 'Martinez', 'Hernandez', 'Lopez', 'Gonzalez', 'Wilson', 'Anderson', 'Thomas',
        'Taylor', 'Moore', 'Jackson', 'Martin', 'Lee', 'Perez', 'Thompson', 'White',
        'Harris', 'Sanchez', 'Clark', 'Ramirez', 'Lewis', 'Robinson', 'Walker', 'Young',
        'Allen', 'King', 'Wright', 'Scott', 'Torres', 'Nguyen', 'Hill', 'Flores',
        'Green', 'Adams', 'Nelson', 'Baker', 'Hall', 'Rivera', 'Campbell', 'Mitchell',
        'Carter', 'Roberts', 'Patel', 'Kumar', 'Singh', 'Chen', 'Wang', 'Kim',
        'Muller', 'Rossi', 'Dubois', 'Silva', 'Ferreira', 'Andersson', 'Kowalski', 'Novak',
    ];

    private const COMPANY_WORDS_A = [
        'Summit', 'Vertex', 'Horizon', 'Cascade', 'Meridian', 'Beacon', 'Anchor', 'Pinnacle',
        'Atlas', 'Northstar', 'Bluewave', 'Ironwood', 'Riverstone', 'Crestline', 'Fairmont', 'Redwood',
    ];

    private const COMPANY_WORDS_B = [
        'Systems', 'Technologies', 'Logistics', 'Industries', 'Solutions', 'Networks', 'Dynamics', 'Partners',
        'Manufacturing', 'Consulting', 'Analytics', 'Materials', 'Robotics', 'Financial', 'Health', 'Energy',
    ];

    /** @param int $baseSeed The run's own seed (RunContext::seed()) - mixed into every draw so different runs produce different data. */
    public function __construct(private readonly int $baseSeed)
    {
    }

    public function fullName(int $sequence): array
    {
        return [
            'firstname' => $this->pickFrom(self::FIRST_NAMES, $sequence, 1),
            'lastname'  => $this->pickFrom(self::LAST_NAMES, $sequence, 2),
        ];
    }

    public function companyName(int $sequence): string
    {
        return $this->pickFrom(self::COMPANY_WORDS_A, $sequence, 1)
            . ' ' . $this->pickFrom(self::COMPANY_WORDS_B, $sequence, 2);
    }

    /** @param array<int,mixed> $items */
    public function pick(array $items, int $sequence): mixed
    {
        $this->seedRng($sequence, 0);
        return $items[array_rand($items)];
    }

    /**
     * @param array<int|string,float> $weighted key => weight (need not sum to 1.0)
     *
     * Return type is deliberately int|string, not string: PHP normalizes
     * purely-numeric string array keys back to int (`["1" => ...]` is
     * actually stored as `[1 => ...]`), so a caller with numeric-looking
     * keys (e.g. star ratings 1-5) cannot force them to stay strings no
     * matter how the array is built. Confirmed empirically - a `string`
     * return type here threw a TypeError the first time a caller used
     * int-like keys.
     */
    public function weightedPick(array $weighted, int $sequence): int|string
    {
        $this->seedRng($sequence, 7);
        $total = array_sum($weighted);
        $roll = mt_rand() / mt_getrandmax() * $total;
        $cursor = 0.0;
        foreach ($weighted as $key => $weight) {
            $cursor += $weight;
            if ($roll <= $cursor) {
                return $key;
            }
        }
        return array_key_last($weighted);
    }

    public function intBetween(int $min, int $max, int $sequence): int
    {
        $this->seedRng($sequence, 11);
        return mt_rand($min, $max);
    }

    public function boolWithProbability(float $probability, int $sequence): bool
    {
        $this->seedRng($sequence, 13);
        return (mt_rand() / mt_getrandmax()) < $probability;
    }

    private function pickFrom(array $pool, int $sequence, int $salt): string
    {
        $this->seedRng($sequence, $salt);
        return $pool[array_rand($pool)];
    }

    private function seedRng(int $sequence, int $salt): void
    {
        mt_srand($this->baseSeed + $sequence * 97 + $salt);
    }
}
