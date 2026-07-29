# Fixture assets

Files here are **development data only**. Nothing in `src/` reads them; they are loaded by
`App\Factory\OrganizationFactory` and therefore by `composer reset` and the test suite.

## `organization-signature.png`

Stands in for an association's signature so a freshly reset database issues a *signed*
reçu fiscal without anyone having to upload anything first — the unsigned path is the one
that is easy to get wrong and hard to notice.

It is the joke stamp supplied with the request, downscaled to 600 × 600 and reduced to
greyscale: the original was 1 039 px and 1.2 MB, over the 1 MB cap
`OrganizationSignature::MAX_FILE_SIZE` enforces. 600 px is still more than 1 000 dpi at the
14 mm the CERFA's signature box allows.

Obviously not a real signature, and it is a picture of a real person in a public
repository — swap it for a scribble if that matters.
