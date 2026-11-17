<?php

declare(strict_types=1);

namespace App\Receipt;

use App\Entity\Organization;
use App\Entity\Person;
use App\ValueObject\Money;
use DateTimeImmutable;
use Symfony\Component\Intl\Countries;

use function assert;

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

    /**
     * One volunteer's receipt for one civil year.
     *
     * `$donationDate` is the **last day of waived travel inside the year**, decided by the
     * caller which has the lines in hand. The form has a single « Date du versement ou du
     * don » and the receipt covers twelve months, so something has to be chosen: a real date
     * the association can point at beats 31 December, which on a year still running would be
     * a date in the future.
     */
    public static function forYear(
        Organization $organization,
        Person $person,
        string $number,
        Money $amount,
        DateTimeImmutable $donationDate,
        DateTimeImmutable $issuedAt,
    ): self {
        $address = $organization->getPostalAddress();

        // The run refuses an association without an address, so reaching here means one.
        assert(null !== $address);

        // Optional by decision: an association that has not uploaded a signature still gets
        // its receipts, and signs them by hand.
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

                // No currency sign and no grouping: « euros » is printed on the form.
                'amount' => $amount->formatBare(),
                'amountInWords' => new AmountInWords()->forCents($amount->cents),

                // Split because the form supplies its own slashes — see CerfaLayout.
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
     * "FR" → "France". The form has a Pays line, and a reader expects a country, not a
     * code. Uses the same Intl country list Address validates against.
     */
    private static function countryName(string $code): string
    {
        return Countries::getName($code, 'fr');
    }
}
