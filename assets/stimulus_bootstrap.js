import { startStimulusApp } from '@symfony/stimulus-bundle';

// The returned app is only needed to register third-party controllers by hand;
// ours are discovered automatically from assets/controllers/.
startStimulusApp();
