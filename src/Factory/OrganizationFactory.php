<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\Organization;
use App\Organization\DefaultTasks;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Organization>
 */
final class OrganizationFactory extends PersistentObjectFactory
{
    public function __construct(
        private readonly DefaultTasks $defaultTasks,
    ) {
        parent::__construct();
    }

    public static function class(): string
    {
        return Organization::class;
    }

    public function inactive(): self
    {
        return $this->with(['active' => false]);
    }

    /**
     * A complete CERFA identity, so a fixture association can actually issue receipts.
     *
     * Without SIREN/RNA and an address, App\Receipt\ReceiptEligibility refuses every
     * receipt — correctly, but it would mean a fresh `composer reset` showed only the
     * refusal path.
     */
    public function withCerfaIdentity(): self
    {
        return $this->with([
            // An RNA number: W + 9 digits, the form for a déclarée association. Invented,
            // like every other fixture value.
            'sirenOrRna' => 'W083001234',
            'addressNumber' => '12',
            'addressStreet' => 'chemin du Lac des Vieilles-Forges',
            'addressPostcode' => '08000',
            'addressCity' => 'Charleville-Mézières',
            'addressCountry' => 'FR',
            'objet' => 'Pratique et enseignement de la voile et des sports nautiques',
        ]);
    }

    /**
     * Every organization gets its starter tasks, exactly as one created
     * through /platform does — so fixtures and tests exercise a shape that can
     * actually be used, and the public form always has choices to offer.
     *
     * This is one of the two explicit call sites of DefaultTasks; see that
     * class for why it is not a Doctrine listener.
     */
    protected function initialize(): static
    {
        return $this->afterPersist(
            function (Organization $organization): void {
                $this->defaultTasks->createFor($organization);
            },
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'name' => self::faker()->unique()->company(),
            // Slugs must match Organization's regex (lowercase, digits, dashes)
            // and be unique, since they address the public volunteer URLs.
            'slug' => self::faker()->unique()->slug(3),
            'active' => true,
        ];
    }
}
