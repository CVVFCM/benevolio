<?php

declare(strict_types=1);

namespace App\Receipt;

use App\Entity\Declaration;
use DateTimeImmutable;
use Symfony\Component\Intl\Countries;

use function abs;
use function assert;
use function intdiv;
use function sprintf;

/**
 * Everything that gets printed on the CERFA, gathered in one place.
 *
 * Between the domain and CerfaLayout's field names, so the generator never reaches into
 * entities and the template never formats anything. Move a value on the form and only the
 * layout changes; change what a value *says* and only this changes.
 */
final readonly class ReceiptValues
{
    private function __construct(
        public string $volunteerName,
        public string $volunteerAddress,
        /** @var array<string, string> */
        private array $fields,
        /** @var array<string, string> */
        private array $images,
    ) {
    }

    public static function from(
        Declaration $declaration,
        string $number,
        int $amountCents,
        DateTimeImmutable $issuedAt,
    ): self {
        $organization = $declaration->getOrganization();
        $person = $declaration->getPerson();
        $address = $organization->getPostalAddress();

        // Eligibility refuses without an address, so reaching here means there is one.
        assert(null !== $address);

        $donationDate = self::latestActionDate($declaration) ?? $issuedAt;

        // The signature is optional, by decision: an association that has not uploaded one
        // still gets its receipt, and signs it by hand. ReceiptEligibility does not ask.
        $signature = $organization->getSignatureDataUri();

        return new self(
            volunteerName: $person->getFullName(),
            volunteerAddress: (string) $person->getAddress(),
            fields: [
                'receiptNumber' => $number,

                'organizationName' => $organization->getName(),
                'sirenOrRna' => (string) $organization->getSirenOrRna(),
                'organizationAddressNumber' => (string) $address->number,
                'organizationAddressStreet' => $address->street,
                'organizationAddressPostcode' => $address->postcode,
                'organizationAddressCity' => $address->city,
                'organizationAddressCountry' => self::countryName($address->country),
                'organizationObjet' => (string) $organization->getObjet(),

                // « Œuvre ou organisme d'intérêt général » and, inside it,
                // « Association loi 1901 » — what every organisation using this
                // application is. Not configurable, because the application only serves
                // associations loi 1901.
                'categoryGeneralInterest' => CerfaLayout::TICK,
                'categoryAssociation1901' => CerfaLayout::TICK,

                'volunteerLastName' => $person->getLastName(),
                'volunteerFirstName' => $person->getFirstName(),
                'volunteerAddressNumber' => (string) $person->getAddress()->number,
                'volunteerAddressStreet' => $person->getAddress()->street,
                'volunteerAddressPostcode' => $person->getAddress()->postcode,
                'volunteerAddressCity' => $person->getAddress()->city,
                'volunteerAddressCountry' => self::countryName($person->getAddress()->country),

                'amount' => self::amount($amountCents),
                'amountInWords' => new AmountInWords()->forCents($amountCents),

                // The date of the donation is the day the volunteering finished, not the
                // day the treasurer got round to validating it. Split because the form
                // supplies its own slashes — see CerfaLayout.
                'donationDay' => $donationDate->format('d'),
                'donationMonth' => $donationDate->format('m'),
                'donationYear' => $donationDate->format('Y'),

                // Article 200, not 978: 978 is the IFI, which this never concerns.
                'article200' => CerfaLayout::TICK,

                // THE box that makes this a receipt for waived volunteer expenses rather
                // than for a cash gift. Without it the document says the wrong thing.
                'natureVolunteerExpenses' => CerfaLayout::TICK,

                'signatureDay' => $issuedAt->format('d'),
                'signatureMonth' => $issuedAt->format('m'),
                'signatureYear' => $issuedAt->format('Y'),
            ],
            images: null === $signature ? [] : ['signature' => $signature],
        );
    }

    /**
     * @return array<string, string>
     */
    public function forOverlay(): array
    {
        return $this->fields;
    }

    /**
     * The image values, keyed by the field names in CerfaLayout::IMAGES.
     *
     * Kept apart from the text fields rather than mixed into them: a `data:` URI is not a
     * value to print, and the template positions the two differently.
     *
     * @return array<string, string>
     */
    public function imagesForOverlay(): array
    {
        return $this->images;
    }

    /**
     * `4325` → `43,25`. Integer arithmetic and a comma, like everywhere else money is
     * rendered in this codebase.
     */
    private static function amount(int $cents): string
    {
        return sprintf('%d,%02d', intdiv($cents, 100), abs($cents % 100));
    }

    /**
     * The last day any of the declared work happened — the date the waiver covers up to.
     */
    private static function latestActionDate(Declaration $declaration): ?DateTimeImmutable
    {
        $latest = null;

        foreach ($declaration->getActions() as $action) {
            $end = $action->getEndDate();

            if (null === $latest || $end > $latest) {
                $latest = $end;
            }
        }

        return $latest;
    }

    /**
     * "FR" → "France". The form has a Pays line, and a reader expects a country, not a
     * code. Uses the same Intl country list Address validates against.
     */
    private static function countryName(string $code): string
    {
        return Countries::getName($code, 'fr');
    }
}
