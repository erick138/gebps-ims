<?php
/** @var \App\Core\Router $router  All endpoints. Filters: auth = signed in, perm:<name> = needs that permission. */

$auth = ['auth'];
    // ---- public
    $router->get('health', 'Health::index');
    $router->get('files', 'Files::serve'); // signed, expiring links to private uploads (checked inside)
    $router->post('auth/login', 'Auth::login');
    $router->post('auth/register', 'Auth::register');
    $router->post('auth/forgot-password', 'Auth::forgotPassword');
    $router->post('auth/reset-password', 'Auth::resetPassword');
    $router->get('public/departments', 'Departments::index'); // lets the sign-up form list departments

    // ---- my account
    $router->get('auth/me', 'Auth::me', ['auth']);
    $router->put('auth/me', 'Auth::updateProfile', ['auth']);
    $router->post('auth/change-password', 'Auth::changePassword', ['auth']);

    // ---- dashboard & alerts
    $router->get('dashboard', 'Dashboard::index', ['auth']);
    $router->get('notifications', 'Notifications::index', ['auth']);
    $router->post('notifications/read', 'Notifications::read', ['auth']);

    // ---- reference data (read: any signed-in user, write: locations / personnel)
    foreach (['departments' => 'Departments', 'rooms' => 'Rooms', 'categories' => 'Categories'] as $path => $ctl) {
        $router->get($path, "$ctl::index", ['auth']);
        $router->get("$path/(:num)", "$ctl::show/$1", ['auth']);
        $router->post($path, "$ctl::create", ['auth', 'perm:locations']);
        $router->put("$path/(:num)", "$ctl::update/$1", ['auth', 'perm:locations']);
        $router->delete("$path/(:num)", "$ctl::delete/$1", ['auth', 'perm:locations']);
    }

    $router->get('personnel', 'Personnel::index', ['auth', 'perm:viewAll']);
    $router->get('personnel/(:num)', 'Personnel::show/$1', ['auth', 'perm:viewAll']);
    $router->get('personnel/(:num)/holdings', 'Personnel::holdings/$1', ['auth', 'perm:viewAll']);
    $router->post('personnel', 'Personnel::create', ['auth', 'perm:personnel']);
    $router->put('personnel/(:num)', 'Personnel::update/$1', ['auth', 'perm:personnel']);
    $router->delete('personnel/(:num)', 'Personnel::delete/$1', ['auth', 'perm:personnel']);

    $router->get('supplies', 'Supplies::index', ['auth', 'perm:viewAll']);
    $router->post('supplies', 'Supplies::create', ['auth', 'perm:equipment']);
    $router->put('supplies/(:num)', 'Supplies::update/$1', ['auth', 'perm:equipment']);
    $router->post('supplies/(:num)/adjust', 'Supplies::adjust/$1', ['auth', 'perm:equipment']);
    $router->delete('supplies/(:num)', 'Supplies::delete/$1', ['auth', 'perm:equipment']);

    // ---- equipment (staff only ever see items assigned to them)
    $router->get('equipment', 'Equipment::index', ['auth']);
    $router->get('equipment/lookup', 'Equipment::lookup', ['auth']);
    $router->get('equipment/mine', 'Equipment::mine', ['auth']);
    $router->get('equipment/(:num)', 'Equipment::show/$1', ['auth']);
    $router->get('equipment/(:num)/history', 'Equipment::history/$1', ['auth']);
    $router->post('equipment', 'Equipment::create', ['auth', 'perm:equipment']);
    $router->put('equipment/(:num)', 'Equipment::update/$1', ['auth', 'perm:equipment']);
    $router->post('equipment/(:num)/photo', 'Equipment::photo/$1', ['auth', 'perm:equipment']);
    $router->delete('equipment/(:num)', 'Equipment::delete/$1', ['auth', 'perm:equipment']);

    // ---- issuance, return, transfer
    $router->get('issuances', 'Issuances::index', ['auth']);
    $router->post('issuances', 'Issuances::create', ['auth', 'perm:issuance']);
    $router->post('issuances/(:num)/return', 'Issuances::returnItem/$1', ['auth', 'perm:issuance']);
    $router->get('transfers', 'Transfers::index', ['auth', 'perm:viewAll']);
    $router->post('transfers', 'Transfers::create', ['auth', 'perm:issuance']);

    // ---- maintenance & repair
    $router->get('maintenance', 'Maintenance::index', ['auth']);
    $router->post('maintenance/request', 'Maintenance::request', ['auth']);
    $router->post('maintenance/schedule', 'Maintenance::schedule', ['auth', 'perm:maintenance']);
    $router->put('maintenance/(:num)', 'Maintenance::update/$1', ['auth', 'perm:maintenance']);
    $router->get('technicians', 'Maintenance::technicians', ['auth', 'perm:maintenance']);

    // ---- equipment requests + attachments
    $router->get('requests', 'Requests::index', ['auth']);
    $router->post('requests', 'Requests::create', ['auth']);
    $router->get('requests/(:num)', 'Requests::show/$1', ['auth']);
    $router->delete('requests/(:num)', 'Requests::delete/$1', ['auth']);
    $router->post('requests/(:num)/attachments', 'Requests::addAttachments/$1', ['auth']);
    $router->get('requests/(:num)/attachments/(:num)', 'Requests::attachment/$1/$2', ['auth']);
    $router->delete('requests/(:num)/attachments/(:num)', 'Requests::removeAttachment/$1/$2', ['auth']);
    $router->post('requests/(:num)/decision', 'Requests::decision/$1', ['auth', 'perm:approve']);

    // ---- barcode / QR physical inventory
    $router->get('verification', 'Verification::index', ['auth', 'perm:tags']);
    $router->post('verification', 'Verification::start', ['auth', 'perm:tags']);
    $router->get('verification/(:num)', 'Verification::show/$1', ['auth', 'perm:tags']);
    $router->post('verification/(:num)/scan', 'Verification::scan/$1', ['auth', 'perm:tags']);
    $router->delete('verification/(:num)/scans/(:num)', 'Verification::undo/$1/$2', ['auth', 'perm:tags']);
    $router->post('verification/(:num)/finish', 'Verification::finish/$1', ['auth', 'perm:tags']);
    $router->post('verification/(:num)/discard', 'Verification::discard/$1', ['auth', 'perm:tags']);

    // ---- reports, SMS, audit trail
    $router->get('reports', 'Reports::index', ['auth', 'perm:reports']);
    $router->get('reports/(:segment)', 'Reports::show/$1', ['auth', 'perm:reports']);
    $router->get('sms/queue', 'Sms::queue', ['auth', 'perm:approve']);
    $router->post('sms/mark-sent', 'Sms::markSent', ['auth', 'perm:approve']);
    $router->get('sms/log', 'Sms::log', ['auth', 'perm:approve']);
    $router->put('sms/settings', 'Sms::settings', ['auth', 'perm:users']);
    $router->get('activity', 'Activity::index', ['auth', 'perm:users']);

    // ---- user accounts (admins; only the super admin can touch admin accounts)
    $router->get('users', 'Users::index', ['auth', 'perm:users']);
    $router->post('users', 'Users::create', ['auth', 'perm:users']);
    $router->put('users/(:num)', 'Users::update/$1', ['auth', 'perm:users']);
    $router->delete('users/(:num)', 'Users::delete/$1', ['auth', 'perm:users']);
    foreach (['approve', 'reject', 'activate', 'deactivate'] as $a) {
        $router->post("users/(:num)/$a", 'Users::' . $a . '/$1', ['auth', 'perm:users']);
    }
    $router->post('users/(:num)/reset-password', 'Users::resetPassword/$1', ['auth', 'perm:users']);
