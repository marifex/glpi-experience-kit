<?php

declare(strict_types=1);

namespace GlpiPlugin\Experiencekit\Infrastructure\Builder;

use Computer;
use Contract;
use ContractType;
use Contract_Supplier;
use GlpiPlugin\Experiencekit\Application\RunContext;
use GlpiPlugin\Experiencekit\Domain\Exception\GenerationException;
use GlpiPlugin\Experiencekit\Domain\GenerationPhase;
use GlpiPlugin\Experiencekit\Infrastructure\Builder\Support\RandomDataProvider;
use GlpiPlugin\Experiencekit\Infrastructure\Builder\Support\SequentialPhaseBuilder;
use Manufacturer;
use Monitor;
use NetworkEquipment;
use Peripheral;
use PeripheralType;
use Phone;
use Printer;
use Software;
use SoftwareLicense;
use SoftwareLicenseType;
use State;
use Supplier;

/**
 * Phase 2: States, Manufacturers, PeripheralType, ContractTypes,
 * SoftwareLicenseTypes, Suppliers, Software, SoftwareLicenses, the six
 * asset types, and Contracts. Depends on OrgStructure's entities/locations.
 *
 * States/Manufacturers/PeripheralType/ContractTypes/SoftwareLicenseTypes are
 * shared GLPI taxonomy dropdowns, not "this run's content" - a fresh GLPI
 * install may already have some of these (confirmed empirically: this dev
 * instance already had "In Use"/"In Repair" states from an earlier data
 * load, and creating them again threw a unicity error). They are resolved
 * idempotently (find-by-name-or-create) via ensureTaxonomy() and
 * deliberately never registered in the plugin's registry - a purge must
 * never remove generic, potentially admin-relied-upon taxonomy values, only
 * this run's actual generated content.
 */
final class CmdbBuilder extends SequentialPhaseBuilder
{
    private const STATES = [
        'In Use'              => 0.75,
        'New - Procurement'    => 0.10,
        'In Repair'            => 0.08,
        'Retired - Disposed'   => 0.07,
    ];

    private const MANUFACTURERS = [
        'Dell', 'HP', 'Lenovo', 'Apple', 'Cisco', 'Microsoft', 'Samsung', 'Brother',
        'Netgear', 'Ubiquiti', 'Logitech', 'ASUS', 'Fortinet', 'Epson',
    ];

    private const PERIPHERAL_TYPE = 'Tablet';

    private const CONTRACT_TYPES = ['Maintenance', 'Support', 'Lease', 'Warranty Extension', 'Managed Services'];

    private const LICENSE_TYPES = ['OEM', 'Volume', 'Subscription', 'Per Device', 'Per User'];

    private const SOFTWARE_PRODUCTS = [
        'Office Suite', 'Design Studio', 'Analytics Platform', 'CRM', 'ERP Connector',
        'Backup Manager', 'Security Shield', 'Cloud Sync', 'Dev Tools', 'Video Conferencing',
        'Project Tracker', 'Database Engine', 'Virtualization Hub', 'Endpoint Agent', 'Firewall Console',
    ];

    private const ASSET_NAME_PREFIXES = [
        'Computer'         => 'PC',
        'Monitor'          => 'MON',
        'NetworkEquipment' => 'NET',
        'Printer'          => 'PRN',
        'Phone'            => 'PHN',
        'Peripheral'       => 'TAB',
    ];

    private const BRANCH_WEIGHTS = ['hq_ny' => 0.50, 'west_austin' => 0.30, 'emea_london' => 0.20];

    /** @var array<string,int>|null Name => id, resolved once per request by ensureTaxonomy(). */
    private ?array $stateIds = null;
    private ?array $manufacturerIds = null;
    private ?array $contractTypeIds = null;
    private ?array $licenseTypeIds = null;
    private ?int $peripheralTypeId = null;

    public function getPhase(): GenerationPhase
    {
        return GenerationPhase::CMDB;
    }

    protected function stages(RunContext $context): array
    {
        $this->ensureTaxonomy();
        $profile = $context->profile;

        $stages = [
            ['itemtype' => 'Supplier', 'target' => $profile->suppliers, 'create' => fn (int $seq) => $this->createSupplier($context, $seq)],
            ['itemtype' => 'Software', 'target' => $profile->software, 'create' => fn (int $seq) => $this->createSoftware($context, $seq)],
            ['itemtype' => 'SoftwareLicense', 'target' => $profile->softwareLicenses, 'create' => fn (int $seq) => $this->createSoftwareLicense($context, $seq)],
        ];

        foreach ($profile->assetCounts as $assetType => $count) {
            $stages[] = [
                'itemtype' => $assetType,
                'target'   => $count,
                'create'   => fn (int $seq) => $this->createAsset($context, $assetType, $seq),
            ];
        }

        $stages[] = ['itemtype' => 'Contract', 'target' => $profile->contracts, 'create' => fn (int $seq) => $this->createContract($context, $seq)];

        return $stages;
    }

    /**
     * Idempotently resolves (find-by-name-or-create) every shared taxonomy
     * dropdown this phase depends on. Safe to call every request - the
     * lookups are cheap (a few dozen small dropdown tables) and results are
     * cached on the instance for the rest of this batch.
     */
    private function ensureTaxonomy(): void
    {
        if ($this->stateIds !== null) {
            return;
        }

        $this->stateIds = [];
        foreach (array_keys(self::STATES) as $name) {
            $this->stateIds[$name] = $this->findOrCreate(State::class, $name);
        }

        $this->manufacturerIds = [];
        foreach (self::MANUFACTURERS as $name) {
            $this->manufacturerIds[$name] = $this->findOrCreate(Manufacturer::class, $name);
        }

        $this->contractTypeIds = [];
        foreach (self::CONTRACT_TYPES as $name) {
            $this->contractTypeIds[$name] = $this->findOrCreate(ContractType::class, $name);
        }

        $this->licenseTypeIds = [];
        foreach (self::LICENSE_TYPES as $name) {
            $this->licenseTypeIds[$name] = $this->findOrCreate(SoftwareLicenseType::class, $name);
        }

        $this->peripheralTypeId = $this->findOrCreate(PeripheralType::class, self::PERIPHERAL_TYPE);
    }

    /**
     * @param class-string<\CommonDBTM> $class
     *
     * Uses find() + take-the-first-row rather than getFromDBByCrit(), which
     * throws TooManyResultsException on an ambiguous match - and duplicate
     * names do happen here: GLPI ships some of these dropdowns pre-seeded
     * (e.g. a stock "OEM" SoftwareLicenseType), so this run may find both a
     * core fixture and an entry from an earlier data load sharing the same
     * name. Either one is an equally valid, pre-existing value to reuse.
     */
    private function findOrCreate(string $class, string $name): int
    {
        $item = new $class();
        $rows = $item->find(['name' => $name], [], 1);
        if (count($rows) > 0) {
            // find() results are keyed by the row's own id.
            return (int) array_key_first($rows);
        }

        $id = $item->add(['name' => $name]);
        $this->assertCreated($id, $class, 0);
        return (int) $id;
    }

    private function createSupplier(RunContext $context, int $seq): void
    {
        $rng = new RandomDataProvider($context->seed());
        $supplier = new Supplier();
        $id = $supplier->add([
            'name'         => $rng->companyName($seq),
            'entities_id'  => $this->orgRootEntityId($context),
            'is_recursive' => 1,
        ]);
        $this->assertCreated($id, 'Supplier', $seq);
        $context->register($this->getPhase(), 'Supplier', (int) $id);
    }

    private function createSoftware(RunContext $context, int $seq): void
    {
        // pickRegistered() round-robins by seq % count in registration order,
        // which is exactly self::MANUFACTURERS's order (see createManufacturer())
        // - indexing the constant the same way keeps the software's display
        // name consistent with its actual manufacturers_id FK.
        $manufacturerName = self::MANUFACTURERS[$seq % count(self::MANUFACTURERS)];
        $manufacturersId = $this->manufacturerIds[$manufacturerName];
        $product = self::SOFTWARE_PRODUCTS[$seq % count(self::SOFTWARE_PRODUCTS)];
        $suffix = intdiv($seq, count(self::SOFTWARE_PRODUCTS));

        $software = new Software();
        $id = $software->add([
            'name'          => $manufacturerName . ' ' . $product . ($suffix > 0 ? ' ' . ($suffix + 1) : ''),
            'entities_id'   => $this->orgRootEntityId($context),
            'is_recursive'  => 1,
            'manufacturers_id' => $manufacturersId,
        ]);
        $this->assertCreated($id, 'Software', $seq);
        $context->register($this->getPhase(), 'Software', (int) $id);
    }

    private function createSoftwareLicense(RunContext $context, int $seq): void
    {
        $rng = new RandomDataProvider($context->seed());
        $softwareIds = $context->registeredIds('Software', $this->getPhase());
        if (count($softwareIds) === 0) {
            throw new GenerationException('Cannot create a SoftwareLicense before any Software exists.');
        }
        $softwaresId = $softwareIds[$seq % count($softwareIds)];
        $licenseTypeId = $this->licenseTypeIds[self::LICENSE_TYPES[$seq % count(self::LICENSE_TYPES)]];

        $license = new SoftwareLicense();
        $id = $license->add([
            'name'                    => 'License #' . ($seq + 1),
            'softwares_id'            => $softwaresId,
            'entities_id'             => $this->orgRootEntityId($context),
            'is_recursive'            => 1,
            'softwarelicensetypes_id' => $licenseTypeId,
            'number'                  => $rng->intBetween(5, 200, $seq),
        ]);
        $this->assertCreated($id, 'SoftwareLicense', $seq);
        $context->register($this->getPhase(), 'SoftwareLicense', (int) $id);
    }

    private function createAsset(RunContext $context, string $assetType, int $seq): void
    {
        $rng = new RandomDataProvider($context->seed());
        $manufacturerName = self::MANUFACTURERS[($seq + crc32($assetType . 'mfr')) % count(self::MANUFACTURERS)];
        $manufacturersId = $this->manufacturerIds[$manufacturerName];
        $stateName = $rng->weightedPick(self::STATES, $seq + crc32($assetType));
        $statesId = $this->stateIds[$stateName];
        $branchesId = $this->branchEntityId($context, $seq + crc32($assetType . 'branch'));
        $locationsId = $this->pickRegistered($context, 'Location', $seq + crc32($assetType . 'loc'));

        $prefix = self::ASSET_NAME_PREFIXES[$assetType] ?? strtoupper(substr($assetType, 0, 3));
        $input = [
            'name'            => sprintf('%s-%05d', $prefix, $seq + 1),
            'entities_id'     => $branchesId,
            'locations_id'    => $locationsId,
            'manufacturers_id' => $manufacturersId,
            'states_id'       => $statesId,
            'serial'          => strtoupper($prefix . bin2hex(random_bytes(4))),
        ];

        if ($assetType === 'Peripheral') {
            $input['peripheraltypes_id'] = $this->peripheralTypeId;
        }

        $class = match ($assetType) {
            'Computer'         => Computer::class,
            'Monitor'          => Monitor::class,
            'NetworkEquipment' => NetworkEquipment::class,
            'Printer'          => Printer::class,
            'Phone'            => Phone::class,
            'Peripheral'       => Peripheral::class,
            default            => throw new GenerationException("Unsupported asset type \"{$assetType}\"."),
        };

        $asset = new $class();
        $id = $asset->add($input);
        $this->assertCreated($id, $assetType, $seq);

        $isRetired = $stateName === 'Retired - Disposed';
        $context->register($this->getPhase(), $assetType, (int) $id, $assetType === 'Computer' && $isRetired ? 'retired_computer' : null);
    }

    private function createContract(RunContext $context, int $seq): void
    {
        $rng = new RandomDataProvider($context->seed());
        $contractTypeId = $this->contractTypeIds[self::CONTRACT_TYPES[$seq % count(self::CONTRACT_TYPES)]];
        $supplierId = $this->pickRegistered($context, 'Supplier', $seq);

        $total = $context->profile->contracts;
        $bucket = $seq < max(1, (int) round($total * 0.10))
            ? 'expired'
            : ($seq < max(2, (int) round($total * 0.20)) ? 'expiring_soon' : 'healthy');

        // duration is in months; begin_date is chosen so that (begin_date +
        // duration) - the contract's effective expiration - lands in the
        // right bucket relative to today.
        $duration = 12;
        $beginDate = match ($bucket) {
            'expired'       => date('Y-m-d', strtotime('-' . $rng->intBetween(13, 24, $seq) . ' months')),
            'expiring_soon' => date('Y-m-d', strtotime('-' . $duration . ' months +' . $rng->intBetween(0, 45, $seq) . ' days')),
            default         => date('Y-m-d', strtotime('-' . $rng->intBetween(0, 6, $seq) . ' months')),
        };

        $contract = new Contract();
        $id = $contract->add([
            'name'            => 'Contract #' . ($seq + 1),
            'entities_id'     => $this->orgRootEntityId($context),
            'is_recursive'    => 1,
            'contracttypes_id' => $contractTypeId,
            'begin_date'      => $beginDate,
            'duration'        => $duration,
        ]);
        $this->assertCreated($id, 'Contract', $seq);

        $link = new Contract_Supplier();
        $link->add(['contracts_id' => (int) $id, 'suppliers_id' => $supplierId]);

        $context->register($this->getPhase(), 'Contract', (int) $id, $bucket);
    }

    // --- shared helpers ------------------------------------------------------

    private function assertCreated($id, string $itemtype, int $seq): void
    {
        if (!$id) {
            throw new GenerationException("Failed to create {$itemtype} at sequence {$seq}.");
        }
    }

    private function orgRootEntityId(RunContext $context): int
    {
        $ids = $context->registeredIds('Entity', GenerationPhase::ORG_STRUCTURE);
        if (count($ids) === 0) {
            throw new GenerationException('Org root entity has not been created yet.');
        }
        return $ids[0];
    }

    /**
     * NOTE: deliberately uses RandomDataProvider::weightedPick() (a proper
     * per-call random roll), not a "seq % totalWeight" bucket lookup like
     * an earlier version of this method did - with a small per-asset-type
     * count (e.g. 40 computers) added to a large per-type offset, every
     * seq in that narrow window lands in the same bucket, so the whole
     * batch would get pinned to a single branch instead of the intended
     * 50/30/20 split. Confirmed empirically: a Small-profile run put 100%
     * of computers in one entity before this was fixed.
     */
    private function branchEntityId(RunContext $context, int $seq): int
    {
        $ids = $context->registeredIds('Entity', GenerationPhase::ORG_STRUCTURE);
        if (count($ids) < 4) {
            throw new GenerationException('Branch entities have not all been created yet.');
        }
        $branchIds = ['hq_ny' => $ids[1], 'west_austin' => $ids[2], 'emea_london' => $ids[3]];
        $rng = new RandomDataProvider($context->seed());
        $key = $rng->weightedPick(self::BRANCH_WEIGHTS, $seq);
        return $branchIds[$key];
    }

    /** Picks one already-registered ID for $itemtype (created earlier in THIS phase), round-robin by $seq. */
    private function pickRegistered(RunContext $context, string $itemtype, int $seq): int
    {
        $ids = $context->registeredIds($itemtype, $this->getPhase());
        if (count($ids) === 0) {
            // Location is registered by OrgStructure, not this phase.
            $ids = $context->registeredIds($itemtype, GenerationPhase::ORG_STRUCTURE);
        }
        if (count($ids) === 0) {
            throw new GenerationException("No {$itemtype} exists yet to reference.");
        }
        return $ids[$seq % count($ids)];
    }
}
