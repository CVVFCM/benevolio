<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Person;
use App\Volunteer\CurrentVolunteer;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `current_volunteer()`: the volunteer logged in for the association on the page, or null.
 * Templates ask this, never `app.user` — see App\Volunteer\CurrentVolunteer.
 */
final class VolunteerExtension extends AbstractExtension
{
    public function __construct(
        private readonly CurrentVolunteer $currentVolunteer,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('current_volunteer', fn (): ?Person => $this->currentVolunteer->get()),
        ];
    }
}
