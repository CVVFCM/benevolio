<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\Organization;
use App\Entity\OrganizationSignature;
use App\Organization\DefaultTasks;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

use function base64_encode;
use function file_get_contents;
use function sprintf;

/**
 * @extends PersistentObjectFactory<Organization>
 */
final class OrganizationFactory extends PersistentObjectFactory
{
    /** The stand-in signature shipped for development; see resources/fixtures/README.md. */
    private const string SIGNATURE_FIXTURE = '/resources/fixtures/organization-signature.png';

    public function __construct(
        private readonly DefaultTasks $defaultTasks,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
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
     * A stored signature, so a fixture association issues a *signed* receipt.
     *
     * Built straight into an OrganizationSignature rather than through
     * Organization::setSignatureUpload(): an UploadedFile means a request, and there is no
     * request here. The upload path is covered by its own test.
     */
    public function withSignature(): self
    {
        $path = $this->projectDir.self::SIGNATURE_FIXTURE;
        $contents = file_get_contents($path);

        if (false === $contents) {
            throw new RuntimeException(sprintf('The fixture signature is missing from %s.', $path));
        }

        return $this->with([
            'signature' => new OrganizationSignature('image/png', base64_encode($contents), 'organization-signature.png'),
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
