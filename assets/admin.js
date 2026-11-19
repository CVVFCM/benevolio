import { Application } from '@hotwired/stimulus';
import TourController from './controllers/tour_controller.js';

// The back offices' own entrypoint, loaded by EasyAdmin (Admin\DashboardController::
// configureAssets). Not app.js: that one starts every controller in assets/controllers/,
// among them the CSRF helper the public forms need and EasyAdmin's forms do not. Here,
// only the guided tour of the dashboard.
const application = Application.start();
application.register('tour', TourController);
