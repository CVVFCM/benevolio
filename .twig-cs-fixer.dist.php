<?php

declare(strict_types=1);

use TwigCsFixer\Config\Config;
use TwigCsFixer\File\Finder;
use TwigCsFixer\Ruleset\Ruleset;
use TwigCsFixer\Standard\Symfony;
use TwigCsFixer\Standard\TwigCsFixer;

// The Symfony standard adds the template naming/location rules (snake_case
// files under templates/, PascalCase under templates/components/) on top of
// the fixer's own whitespace, quoting and punctuation rules.
$ruleset = (new Ruleset())
    ->addStandard(new TwigCsFixer())
    ->addStandard(new Symfony());

$finder = (new Finder())->in(__DIR__.'/templates');

return (new Config('benevolio'))
    ->setRuleset($ruleset)
    ->setFinder($finder)
    ->setCacheFile(__DIR__.'/var/twig-cs-fixer.cache');
