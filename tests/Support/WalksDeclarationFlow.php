<?php

declare(strict_types=1);

namespace App\Tests\Support;

use DateTimeImmutable;

/**
 * Walks the public declaration form, step by step, the way a volunteer does.
 *
 * The using class holds its client in `$this->client`, and creates the association
 * whose slug it passes before walking — `les-jardins` unless told otherwise.
 */
trait WalksDeclarationFlow
{
    /** The form's name, which prefixes every field. */
    private const string FORM = 'declaration_flow';

    private const string FLOW_SLUG = 'les-jardins';

    /**
     * The identity step's fields, filled.
     *
     * @param array<string, string> $overrides keyed by field name, `email` or `addressCity`
     *
     * @return array<string, string>
     */
    private function personStep(array $overrides = []): array
    {
        $values = [
            'firstName' => 'Jean',
            'lastName' => 'Dupont',
            'email' => 'jean.dupont@example.test',
            'addressNumber' => '12',
            'addressStreet' => 'rue des Jardins',
            'addressPostcode' => '44000',
            'addressCity' => 'Nantes',
            'addressCountry' => 'FR',
            ...$overrides,
        ];

        $fields = [];
        foreach ($values as $name => $value) {
            $fields[self::FORM.'[person]['.$name.']'] = $value;
        }

        return $fields;
    }

    /**
     * Fills the first two steps and stops on the legal statements.
     *
     * Advancing a step renders the next one directly; FormFlow does not do
     * POST-redirect-GET between steps (it guards against a POST reload itself via
     * isCurrentStepSubmitted()). Only the final submit redirects.
     *
     * The action is dated thirty days back: it must be over, and a fixed date would
     * one day fall outside whatever window a test checks.
     *
     * @param array<string, string> $personOverrides
     */
    private function reachLegalStep(array $personOverrides = [], string $slug = self::FLOW_SLUG): void
    {
        $this->client->request('GET', '/a/'.$slug.'/declaration');
        $this->client->submitForm('Suivant', $this->personStep($personOverrides));

        $this->client->submitForm('Suivant', [
            self::FORM.'[actions][actions][0][task]' => $this->firstTaskId(),
            self::FORM.'[actions][actions][0][title]' => 'Régate du printemps',
            self::FORM.'[actions][actions][0][date]' => new DateTimeImmutable('-30 days')->format('Y-m-d'),
            self::FORM.'[actions][actions][0][consecutiveDays]' => '2',
            self::FORM.'[actions][actions][0][workHours]' => '6.5',
            self::FORM.'[actions][actions][0][distanceKm]' => '12',
            self::FORM.'[actions][actions][0][journeys]' => '2',
        ]);
    }

    /**
     * Fills every step and submits. The response is the redirect to the thank-you page.
     *
     * @param array<string, string> $personOverrides
     */
    private function completeFlow(array $personOverrides = [], string $slug = self::FLOW_SLUG): void
    {
        $this->reachLegalStep($personOverrides, $slug);

        $this->client->submitForm('Enregistrer ma déclaration', [
            self::FORM.'[legal][accuracyAttested]' => '1',
            self::FORM.'[legal][expensesWaived]' => '1',
        ]);
    }

    /**
     * The id of the first task the actions step offers — read from the rendered select
     * rather than hardcoded, so a test breaks if the option stops being offered.
     */
    private function firstTaskId(): string
    {
        $option = $this->client->getCrawler()
            ->filter('select[name="'.self::FORM.'[actions][actions][0][task]"] option[value!=""]')
            ->first();

        self::assertGreaterThan(0, $option->count(), 'The actions step offers no task.');

        return (string) $option->attr('value');
    }
}
