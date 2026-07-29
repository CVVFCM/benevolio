<?php

declare(strict_types=1);

namespace App\Receipt;

/**
 * Where each value sits on CERFA 2041-RD.
 *
 * ONE PLACE, on purpose. The form is stamped, not filled — it carries no form fields —
 * so every value is positioned by hand, and a revision of the form moves all of them.
 * Keeping the numbers here means re-measuring touches this file and nothing else.
 *
 * **Millimetres from the top-left of the page**, matching the CSS the overlay template
 * emits. The figures were measured off the shipped PDF with `pdftotext -bbox`, whose
 * boxes are in points, converted at 25.4/72 — they are not guesses, but they are also
 * not exact: the values sit on dotted rules, so a millimetre either way is invisible.
 *
 * The two pages matter. The organisation block is on **page 1**; the donor, the amount
 * and the signature are on **page 2**. `qpdf --overlay` maps overlay page n onto form
 * page n, so the layer has to be two pages too.
 *
 * @see resources/cerfa/README.md for what to do when the form is revised.
 */
final class CerfaLayout
{
    /** The dotted rules sit at roughly this size; matching it keeps values on the line. */
    public const string FONT_SIZE = '9pt';

    /** What a ticked box looks like. A plain X prints everywhere and photocopies. */
    public const string TICK = 'X';

    /**
     * page => field => [x, y] in millimetres, or [x, y, width, align] where a value
     * needs to be boxed (the amount is right-aligned against "Euros").
     *
     * @var array<int, array<string, array{float, float}|array{float, float, float, string}>>
     */
    public const array FIELDS = [
        1 => [
            // The « Numéro d'ordre du reçu » box, under its label at y=34.3.
            'receiptNumber' => [150.0, 39.0],

            // « Nom ou dénomination : » ends at x=54.6, y=56.5.
            'organizationName' => [57.0, 56.8],

            // « Numéro SIREN ou RNA¹ : » — the rule starts after the colon.
            'sirenOrRna' => [63.0, 65.2],

            // « N° …… Rue …… » at y=73.9.
            'organizationAddressNumber' => [21.0, 74.2],
            'organizationAddressStreet' => [47.0, 74.2],

            // « Code postal …… Commune …… » at y=78.8.
            'organizationAddressPostcode' => [35.0, 79.1],
            'organizationAddressCity' => [82.0, 79.1],

            // « Pays : …… » at y=83.1.
            'organizationAddressCountry' => [22.0, 83.4],

            // « Objet : …… » at y=87.9.
            'organizationObjet' => [24.0, 88.2],

            // The outer box for « Œuvre ou organisme d'intérêt général … », in the left
            // margin of that block.
            'categoryGeneralInterest' => [9.4, 120.3],

            // The ○ before « Association loi 1901 », measured at x=22.4-25.3, y=120.7.
            'categoryAssociation1901' => [22.6, 120.4],
        ],
        2 => [
            // « Nom : …… » / « Prénoms : …… » at y≈85.7.
            'volunteerLastName' => [24.0, 86.2],
            'volunteerFirstName' => [126.0, 86.2],

            // « N° …… Rue …… » at y=96.0.
            'volunteerAddressNumber' => [21.0, 96.3],
            'volunteerAddressStreet' => [47.0, 96.3],

            // « Code postal …… Commune …… » at y=101.1.
            'volunteerAddressPostcode' => [35.0, 101.4],
            'volunteerAddressCity' => [82.0, 101.4],

            // « Pays : …… » at y=106.4.
            'volunteerAddressCountry' => [22.0, 106.7],

            // The amount, right-aligned so it finishes just before « Euros » (x=56.8).
            'amount' => [24.0, 125.4, 31.0, 'right'],

            // « Somme en toutes lettres : …… » — the rule starts after the colon at
            // x=123.6.
            'amountInWords' => [125.0, 125.4],

            // « Date du versement ou du don : ……/……/…… » — after the colon at x=62.8.
            'donationDate' => [65.0, 132.7],

            // « 200 du CGI », whose box sits to its left ("200" begins at x=38.7).
            'article200' => [34.6, 155.6],

            // « Frais engagés par les bénévoles, dont ils renoncent expressément au
            // remboursement » — THE box that makes this receipt what it is.
            'natureVolunteerExpenses' => [12.0, 188.7],

            'signatureDate' => [140.0, 218.0],
        ],
    ];
}
