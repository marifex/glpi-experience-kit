<?php

declare(strict_types=1);

namespace GlpiPlugin\Experiencekit\Infrastructure\Builder;

use CommonITILObject;
use CommonITILSatisfaction;
use Document;
use Document_Item;
use GlpiPlugin\Experiencekit\Application\RunContext;
use GlpiPlugin\Experiencekit\Domain\Exception\GenerationException;
use GlpiPlugin\Experiencekit\Domain\GenerationPhase;
use GlpiPlugin\Experiencekit\Infrastructure\Builder\Support\RandomDataProvider;
use GlpiPlugin\Experiencekit\Infrastructure\Builder\Support\SequentialPhaseBuilder;
use KnowbaseItem;
use KnowbaseItemCategory;
use TicketSatisfaction;

/**
 * Phase 6: Knowledge base articles, Document attachments (~30% of all
 * tickets, via 10 reusable placeholder Documents rather than one unique
 * file per ticket), and TicketSatisfaction surveys (~30% of closed
 * tickets, weighted toward positive ratings).
 *
 * KB categories and the 10 Document templates are shared taxonomy, same
 * reasoning as CmdbBuilder/ItsmConfigBuilder: idempotent find-or-create,
 * registered only when actually created by this run.
 */
final class KbAttachmentSurveyBuilder extends SequentialPhaseBuilder
{
    private const KB_CATEGORIES = [
        'Getting Started', 'Hardware', 'Software', 'Network & Connectivity',
        'Security', 'Account Management', 'Facilities', 'Policies & Procedures',
    ];

    private const KB_TOPICS = [
        'How to reset your password', 'Connecting to the corporate VPN', 'Setting up email on your mobile device',
        'Requesting new software installation', 'Troubleshooting printer connectivity', 'Wifi setup for new employees',
        'Requesting a replacement laptop', 'Booking a conference room', 'Submitting an expense report',
        'Reporting a security incident', 'Setting up multi-factor authentication', 'Accessing shared network drives',
        'Requesting building access badge', 'Configuring email signature', 'Troubleshooting slow computer performance',
        'How to submit a support ticket', 'Standard equipment for new hires', 'Remote work equipment policy',
        'Software license request process', 'Data backup and recovery procedures',
    ];

    private const DOCUMENT_COUNT = 10;
    private const ATTACHMENT_RATE = 0.30;
    private const SURVEY_RATE = 0.30;
    /** @var array<int,float> star rating => probability weight */
    private const SURVEY_WEIGHTS = [1 => 0.05, 2 => 0.07, 3 => 0.18, 4 => 0.35, 5 => 0.35];

    /** @var array<string,int>|null category name => id */
    private ?array $categoryIds = null;
    /** @var int[]|null */
    private ?array $documentIds = null;

    public function getPhase(): GenerationPhase
    {
        return GenerationPhase::KB_ATTACHMENTS_SURVEYS;
    }

    protected function stages(RunContext $context): array
    {
        $this->ensureFoundation($context);

        $allTicketIds = $context->registeredIds('Ticket');
        $attachmentTarget = (int) round(count($allTicketIds) * self::ATTACHMENT_RATE);

        $closedTicketCount = $this->closedTicketCount($context, $allTicketIds);
        $surveyTarget = (int) round($closedTicketCount * self::SURVEY_RATE);

        return [
            ['itemtype' => 'KnowbaseItem', 'target' => $context->profile->kbArticles, 'create' => fn (int $seq) => $this->createArticle($context, $seq)],
            // NOT itemtype 'Ticket': these tickets are already registered by
            // whichever earlier phase created them, and the registry's
            // UNIQUE(itemtype, items_id) means registering the same ticket
            // a second time would fail outright. Registering the new
            // Document_Item/TicketSatisfaction row itself is both correct
            // (that row is genuinely new, purge-safe content) and avoids
            // the collision entirely.
            ['itemtype' => 'Document_Item', 'target' => $attachmentTarget, 'create' => fn (int $seq) => $this->attachDocument($context, $seq, $allTicketIds)],
            ['itemtype' => 'TicketSatisfaction', 'target' => $surveyTarget, 'create' => fn (int $seq) => $this->createSurvey($context, $seq)],
        ];
    }

    private function ensureFoundation(RunContext $context): void
    {
        if ($this->categoryIds !== null) {
            return;
        }

        $rootId = $this->orgRootEntityId($context);

        $this->categoryIds = [];
        foreach (self::KB_CATEGORIES as $name) {
            $category = new KnowbaseItemCategory();
            $rows = $category->find(['name' => $name], [], 1);
            $this->categoryIds[$name] = count($rows) > 0
                ? (int) array_key_first($rows)
                : (int) $category->add(['name' => $name, 'entities_id' => $rootId, 'is_recursive' => 1]);
        }

        $this->documentIds = [];
        for ($i = 1; $i <= self::DOCUMENT_COUNT; $i++) {
            $name = sprintf('Ticket Attachment Template %02d', $i);
            $document = new Document();
            $rows = $document->find(['name' => $name], [], 1);
            if (count($rows) > 0) {
                $this->documentIds[] = (int) array_key_first($rows);
                continue;
            }

            $filename = sprintf('experiencekit_attachment_%02d_%d.txt', $i, $context->seed());
            file_put_contents(GLPI_TMP_DIR . '/' . $filename, "Placeholder attachment content #{$i}.\nGenerated by GLPI Experience Kit.\n");

            $id = $document->add([
                'name'       => $name,
                'entities_id' => $rootId,
                'is_recursive' => 1,
                '_filename'  => [$filename],
            ]);
            $this->assertCreated($id, 'Document', $i);
            $this->documentIds[] = (int) $id;
        }
    }

    private function createArticle(RunContext $context, int $seq): void
    {
        $rng = new RandomDataProvider($context->seed());
        $topic = self::KB_TOPICS[$seq % count(self::KB_TOPICS)];
        $suffix = intdiv($seq, count(self::KB_TOPICS));
        $categoryName = array_keys($this->categoryIds)[$seq % count($this->categoryIds)];
        $isFaq = $rng->boolWithProbability(0.30, $seq);

        $article = new KnowbaseItem();
        $id = $article->add([
            'name'        => $topic . ($suffix > 0 ? ' (' . ($suffix + 1) . ')' : ''),
            'answer'      => 'Step-by-step guidance: ' . $topic . '. Contact the service desk if this does not resolve your issue.',
            'is_faq'      => $isFaq ? 1 : 0,
            '_categories' => [$this->categoryIds[$categoryName]],
        ]);
        $this->assertCreated($id, 'KnowbaseItem', $seq);

        $context->register($this->getPhase(), 'KnowbaseItem', (int) $id);
    }

    /** @param int[] $allTicketIds */
    private function attachDocument(RunContext $context, int $seq, array $allTicketIds): void
    {
        if (!isset($allTicketIds[$seq])) {
            throw new GenerationException("No ticket at sequence {$seq} to attach a document to.");
        }
        $ticketsId = $allTicketIds[$seq];
        $documentsId = $this->documentIds[$seq % count($this->documentIds)];

        $link = new Document_Item();
        $linkId = $link->add(['documents_id' => $documentsId, 'items_id' => $ticketsId, 'itemtype' => 'Ticket']);
        $this->assertCreated($linkId, 'Document_Item', $seq);

        $context->register($this->getPhase(), 'Document_Item', (int) $linkId);
    }

    private function createSurvey(RunContext $context, int $seq): void
    {
        $closedIds = $this->closedTicketIds($context, $context->registeredIds('Ticket'));
        if (!isset($closedIds[$seq])) {
            throw new GenerationException("No closed ticket at sequence {$seq} for a survey.");
        }
        $ticketsId = $closedIds[$seq];

        $rng = new RandomDataProvider($context->seed());
        $rating = (int) $rng->weightedPick(self::SURVEY_WEIGHTS, $seq);

        $satisfaction = new TicketSatisfaction();
        $id = $satisfaction->add([
            'tickets_id'    => $ticketsId,
            'type'          => CommonITILSatisfaction::TYPE_INTERNAL,
            'date_answered' => date('Y-m-d H:i:s', strtotime('-' . $rng->intBetween(0, 60, $seq) . ' days')),
            'satisfaction'  => $rating,
        ]);
        $this->assertCreated($id, 'TicketSatisfaction', $seq);

        // $id here is the ticket's own id, not the satisfaction row's
        // auto-increment id: TicketSatisfaction::getIndexName() returns
        // 'tickets_id' (one survey per ticket, so it's the natural
        // identifier), and CommonDBTM::add()/getFromDB()/delete() all key
        // off getIndexName() consistently - confirmed empirically this is
        // exactly what a later getFromDB()/delete() call needs, despite
        // looking like the wrong id at first glance.
        $context->register($this->getPhase(), 'TicketSatisfaction', (int) $id);
    }

    /** @param int[] $allTicketIds @return int[] */
    private function closedTicketIds(RunContext $context, array $allTicketIds): array
    {
        static $cache = [];
        $cacheKey = $context->runId();
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        global $DB;
        if (count($allTicketIds) === 0) {
            return $cache[$cacheKey] = [];
        }

        $ids = [];
        foreach ($DB->request([
            'SELECT' => 'id',
            'FROM'   => 'glpi_tickets',
            'WHERE'  => ['id' => $allTicketIds, 'status' => CommonITILObject::CLOSED],
        ]) as $row) {
            $ids[] = (int) $row['id'];
        }
        return $cache[$cacheKey] = $ids;
    }

    /** @param int[] $allTicketIds */
    private function closedTicketCount(RunContext $context, array $allTicketIds): int
    {
        return count($this->closedTicketIds($context, $allTicketIds));
    }

    private function orgRootEntityId(RunContext $context): int
    {
        $ids = $context->registeredIds('Entity', GenerationPhase::ORG_STRUCTURE);
        if (count($ids) === 0) {
            throw new GenerationException('Org root entity has not been created yet.');
        }
        return $ids[0];
    }

    private function assertCreated($id, string $itemtype, int $seq): void
    {
        if (!$id) {
            throw new GenerationException("Failed to create {$itemtype} at sequence {$seq}.");
        }
    }
}
