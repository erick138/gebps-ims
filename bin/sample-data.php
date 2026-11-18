<?php
/** OPTIONAL demo data for trying the system:  php bin/sample-data.php   (refuses to run when APP_ENV=production) */
require __DIR__ . '/../app/bootstrap.php';

use App\Libraries\Db;

if (ENVIRONMENT === 'production') {
    fwrite(STDERR, "Refusing to load demo data while APP_ENV=production. Set APP_ENV=development in .env first.\n");
    exit(1);
}

Db::transaction(function () {
            
            Db::run("INSERT IGNORE INTO departments (code, name, head) VALUES
                ('ELEM','Elementary Department','Principal'),('ICT','ICT Office','ICT Coordinator'),('ADM','Administration','School Head')");
            $dept = fn (string $code) => (int) Db::value('SELECT id FROM departments WHERE code = ?', [$code]);

            foreach ([['Grade 6 - Rizal', 'Classroom', 'ELEM'], ['Computer Laboratory', 'Laboratory', 'ICT'], ["Principal's Office", 'Office', 'ADM']] as [$n, $t, $c]) {
                Db::run('INSERT IGNORE INTO rooms (name, type, department_id) VALUES (?,?,?)', [$n, $t, $dept($c)]);
            }
            Db::run("INSERT IGNORE INTO personnel (emp_no, name, designation, department_id, contact, status) VALUES (?,?,?,?,?, 'Active')",
                ['2026-001', 'Maria Santos', 'Teacher III', $dept('ELEM'), '09171234567']);

            $cat  = fn (string $code) => (int) Db::value('SELECT id FROM categories WHERE code = ?', [$code]);
            $room = fn (string $name) => (int) Db::value('SELECT id FROM rooms WHERE name = ?', [$name]);
            foreach ([
                ['Laptop', 'Acer', 'Aspire 5', 'SN-DEMO-001', 'IT', 'Computer Laboratory'],
                ['LED Projector', 'Epson', 'EB-X06', 'SN-DEMO-002', 'AV', 'Grade 6 - Rizal'],
                ['Teacher Table', 'Local', 'Wood', 'SN-DEMO-003', 'FUR', 'Grade 6 - Rizal'],
            ] as [$name, $brand, $model, $serial, $c, $r]) {
                if (! Db::one('SELECT 1 FROM equipment WHERE serial_no = ?', [$serial])) {
                    Db::insert('equipment', [
                        'name' => $name, 'brand' => $brand, 'model' => $model, 'serial_no' => $serial,
                        'category_id' => $cat($c), 'room_id' => $room($r),  'condition' => 'Good',
                    ]);
                }
            }
            if (! Db::one("SELECT 1 FROM supplies WHERE name = 'Bond Paper A4'")) {
                Db::insert('supplies', ['name' => 'Bond Paper A4', 'unit' => 'ream', 'qty' => 3, 'reorder_level' => 5]);
            }
        });

echo "Demo data loaded.\n";
