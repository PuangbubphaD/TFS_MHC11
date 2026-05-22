<?php
require_once __DIR__ . '/includes/db.php';

if (!defined('ALLOW_ADMIN_TOOLS') || ALLOW_ADMIN_TOOLS !== true) {
    http_response_code(403);
    die('<div style="font-family:sans-serif;padding:2rem;background:#fee;border:1px solid #f00;border-radius:8px;margin:2rem">
        <h3>❌ Access Denied</h3>
        <p>เพื่อความปลอดภัย เครื่องมือผู้ดูแลระบบนี้ถูกปิดใช้งานในไฟล์กำหนดค่าหลัก</p>
    </div>');
}

require_once __DIR__ . '/includes/functions.php';

// Helper Function to seed activity phases
function seedActivityPhases($pdo, $activity_id, $startDateStr, $isCompleted = false) {
    $start = new DateTime($startDateStr);
    $rules = [
        [1, 'ขออนุมัติจัดกิจกรรม', 'ยื่นขออนุมัติและให้ผู้อำนวยการลงนาม ไม่น้อยกว่า 35 วันทำการก่อนจัดกิจกรรม', 35, 'before'],
        [2, 'ขออนุมัติงบประมาณและพัสดุ', 'ยืมเงินโครงการและจัดซื้อ/จัดจ้างพัสดุ ไม่น้อยกว่า 28 วันทำการก่อนจัดกิจกรรม', 28, 'before'],
        [3, 'ขออนุมัติบุคลากรและเชิญผู้ร่วม', 'เชิญผู้เข้าร่วมโครงการไม่น้อยกว่า 14 วันทำการก่อนจัดกิจกรรม และเตรียมคณะทำงานไม่น้อยกว่า 7 วันทำการก่อนจัดกิจกรรม', 14, 'before'],
        [4, 'ดำเนินการจัดกิจกรรม', 'จัดกิจกรรมและกำกับดูแลให้เป็นไปตามแผน', 0,  'on'],
        [5, 'การจัดทำ onepage', 'จัดทำเอกสารสรุปกิจกรรมหน้าเดียว (One Page Summary)', 5, 'after'],
        [6, 'เบิกจ่ายงบประมาณ', 'ยื่นเอกสารเบิกจ่ายต่องานการเงิน', 7,  'after'],
        [7, 'สรุปกิจกรรม', 'จัดทำรายงานสรุปผลการจัดกิจกรรม', 10, 'after'],
        [8, 'ผู้อำนวยการลงนามอนุมัติสรุป', 'ผู้อำนวยการลงนามในรายงานสรุปผลกิจกรรม', 14, 'after'],
    ];

    foreach ($rules as [$num, $name, $desc, $days, $type]) {
        $deadline_str = getWorkingDayDeadline($pdo, $startDateStr, $days, $type);
        
        $status = $isCompleted ? 'completed' : 'pending';
        $comp_date = $isCompleted ? $deadline_str : null;
        
        $pdo->prepare("INSERT INTO activity_phases (activity_id, phase_number, phase_name, description, deadline_date, status, completed_date) VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$activity_id, $num, $name, $desc, $deadline_str, $status, $comp_date]);
    }
}

try {
    // 1. Clear existing data (Outside transaction because TRUNCATE commits)
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    $pdo->exec("TRUNCATE TABLE attachments");
    $pdo->exec("TRUNCATE TABLE activity_reports");
    $pdo->exec("TRUNCATE TABLE activity_phases");
    $pdo->exec("TRUNCATE TABLE activities");
    $pdo->exec("TRUNCATE TABLE project_phases");
    $pdo->exec("TRUNCATE TABLE projects");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

    $pdo->beginTransaction();

    $today = new DateTime();

    // ----------- PROJECT 1: COMPLETED PROJECT (Mental Health in Schools) -----------
    $p1Start = (clone $today)->modify('-60 days')->format('Y-m-d');
    $pdo->prepare("INSERT INTO projects (user_id, title, budget_total, start_date, status) VALUES (?,?,?,?,'completed')")
        ->execute([3, 'โครงการส่งเสริมสุขภาพจิตเชิงรุกในโรงเรียน (School Mental Health)', 800000, $p1Start]);
    $p1 = $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO project_phases (project_id, phase_number, phase_name, deadline_date, status, completed_date) VALUES (?,1,'ขออนุมัติโครงการ',?,'completed',?)")
        ->execute([$p1, (clone $today)->modify('-65 days')->format('Y-m-d'), (clone $today)->modify('-64 days')->format('Y-m-d')]);
    $pdo->prepare("INSERT INTO project_phases (project_id, phase_number, phase_name, deadline_date, status, completed_date) VALUES (?,2,'สรุปโครงการ',?,'completed',?)")
        ->execute([$p1, (clone $today)->modify('-5 days')->format('Y-m-d'), (clone $today)->modify('-4 days')->format('Y-m-d')]);

    // Activity 1.1
    $pdo->prepare("INSERT INTO activities (project_id, activity_name, planned_start, planned_budget, status) VALUES (?,?,?,?,'completed')")
        ->execute([$p1, 'กิจกรรมอบรมครูแนะแนวเรื่องการปฐมพยาบาลทางใจ', (clone $today)->modify('-50 days')->format('Y-m-d'), 300000]);
    $a1 = $pdo->lastInsertId();
    seedActivityPhases($pdo, $a1, (clone $today)->modify('-50 days')->format('Y-m-d'), true);
    
    // Activity 1.2
    $pdo->prepare("INSERT INTO activities (project_id, activity_name, planned_start, planned_budget, status) VALUES (?,?,?,?,'completed')")
        ->execute([$p1, 'ค่ายเยาวชนสร้างภูมิคุ้มกันทางใจ', (clone $today)->modify('-30 days')->format('Y-m-d'), 450000]);
    $a2 = $pdo->lastInsertId();
    seedActivityPhases($pdo, $a2, (clone $today)->modify('-30 days')->format('Y-m-d'), true);

    $pdo->prepare("INSERT INTO activity_reports (activity_id, report_date, participants, budget_spent, summary, reported_by) VALUES (?,?,?,?,?,?)")
        ->execute([$a1, (clone $today)->modify('-48 days')->format('Y-m-d'), 150, 295000, 'ครูแนะแนวให้ความสนใจและได้ทดลองฝึกทักษะการฟังอย่างลึกซึ้ง (Deep Listening)', 3]);
    $pdo->prepare("INSERT INTO activity_reports (activity_id, report_date, participants, budget_spent, summary, reported_by) VALUES (?,?,?,?,?,?)")
        ->execute([$a2, (clone $today)->modify('-28 days')->format('Y-m-d'), 200, 448000, 'นักเรียนมีความเข้าใจเรื่องอารมณ์ตนเองมากขึ้น กิจกรรมบรรลุวัตถุประสงค์', 3]);


    // ----------- PROJECT 2: ACTIVE PROJECT (Mental Health Hotline) -----------
    $p2Start = (clone $today)->modify('+10 days')->format('Y-m-d');
    $pdo->prepare("INSERT INTO projects (user_id, title, budget_total, start_date, status) VALUES (?,?,?,?,'active')")
        ->execute([4, 'โครงการพัฒนาระบบสายด่วนสุขภาพจิตชุมชน', 1500000, $p2Start]);
    $p2 = $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO project_phases (project_id, phase_number, phase_name, deadline_date, status, completed_date) VALUES (?,1,'ขออนุมัติโครงการ',?,'completed',?)")
        ->execute([$p2, (clone $today)->modify('-10 days')->format('Y-m-d'), (clone $today)->modify('-8 days')->format('Y-m-d')]);
    $pdo->prepare("INSERT INTO project_phases (project_id, phase_number, phase_name, deadline_date, status) VALUES (?,2,'สรุปโครงการ',?,'pending')")
        ->execute([$p2, (clone $today)->modify('+180 days')->format('Y-m-d')]);

    // Activity 2.1 (Ongoing)
    $pdo->prepare("INSERT INTO activities (project_id, activity_name, planned_start, planned_budget, status) VALUES (?,?,?,?,'ongoing')")
        ->execute([$p2, 'จัดซื้อและติดตั้งระบบชุมสายโทรศัพท์', (clone $today)->modify('-2 days')->format('Y-m-d'), 800000]);
    $a3 = $pdo->lastInsertId();
    seedActivityPhases($pdo, $a3, (clone $today)->modify('-2 days')->format('Y-m-d'), false);
    $pdo->prepare("UPDATE activity_phases SET status='completed', completed_date=NOW() WHERE activity_id=? AND phase_number IN (1,2)")
        ->execute([$a3]);

    // Activity 2.2 (Planned)
    $pdo->prepare("INSERT INTO activities (project_id, activity_name, planned_start, planned_budget, status) VALUES (?,?,?,?,'planned')")
        ->execute([$p2, 'อบรมอาสาสมัครรับสายด่วน (Call Center)', (clone $today)->modify('+25 days')->format('Y-m-d'), 350000]);
    $a4 = $pdo->lastInsertId();
    seedActivityPhases($pdo, $a4, (clone $today)->modify('+25 days')->format('Y-m-d'), false);

    // Activity 2.3 (Planned)
    $pdo->prepare("INSERT INTO activities (project_id, activity_name, planned_start, planned_budget, status) VALUES (?,?,?,?,'planned')")
        ->execute([$p2, 'ผลิตสื่อประชาสัมพันธ์ช่องทางสายด่วน', (clone $today)->modify('+40 days')->format('Y-m-d'), 200000]);
    $a5 = $pdo->lastInsertId();
    seedActivityPhases($pdo, $a5, (clone $today)->modify('+40 days')->format('Y-m-d'), false);


    // ----------- PROJECT 3: OVERDUE PROJECT (Workplace Wellness) -----------
    $p3Start = (clone $today)->modify('-20 days')->format('Y-m-d');
    $pdo->prepare("INSERT INTO projects (user_id, title, budget_total, start_date, status) VALUES (?,?,?,?,'active')")
        ->execute([3, 'โครงการวัคซีนใจในที่ทำงาน (Workplace Wellness)', 400000, $p3Start]);
    $p3 = $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO project_phases (project_id, phase_number, phase_name, deadline_date, status, completed_date) VALUES (?,1,'ขออนุมัติโครงการ',?,'completed',?)")
        ->execute([$p3, (clone $today)->modify('-25 days')->format('Y-m-d'), (clone $today)->modify('-20 days')->format('Y-m-d')]);
    $pdo->prepare("INSERT INTO project_phases (project_id, phase_number, phase_name, deadline_date, status) VALUES (?,2,'สรุปโครงการ',?,'pending')")
        ->execute([$p3, (clone $today)->modify('+30 days')->format('Y-m-d')]);

    // Activity 3.1 (Overdue)
    $pdo->prepare("INSERT INTO activities (project_id, activity_name, planned_start, planned_budget, status) VALUES (?,?,?,?,'ongoing')")
        ->execute([$p3, 'กิจกรรมคัดกรองความเครียดพนักงาน', (clone $today)->modify('-15 days')->format('Y-m-d'), 100000]);
    $a6 = $pdo->lastInsertId();
    seedActivityPhases($pdo, $a6, (clone $today)->modify('-15 days')->format('Y-m-d'), false);
    $pdo->prepare("UPDATE activity_phases SET status='overdue' WHERE activity_id=? AND phase_number IN (1,2,3)")
        ->execute([$a6]);

    // ----------- PROJECT 4: TELE-PSYCHIATRY (4 Activities) -----------
    $p4Start = (clone $today)->modify('-5 days')->format('Y-m-d');
    $pdo->prepare("INSERT INTO projects (user_id, title, budget_total, start_date, status) VALUES (?,?,?,?,'active')")
        ->execute([3, 'โครงการจิตเวชทางไกลสำหรับพื้นที่ห่างไกล (Tele-Psychiatry)', 2500000, $p4Start]);
    $p4 = $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO project_phases (project_id, phase_number, phase_name, deadline_date, status, completed_date) VALUES (?,1,'ขออนุมัติโครงการ',?,'completed',?)")
        ->execute([$p4, (clone $today)->modify('-10 days')->format('Y-m-d'), (clone $today)->modify('-7 days')->format('Y-m-d')]);
    $pdo->prepare("INSERT INTO project_phases (project_id, phase_number, phase_name, deadline_date, status) VALUES (?,2,'สรุปโครงการ',?,'pending')")
        ->execute([$p4, (clone $today)->modify('+250 days')->format('Y-m-d')]);

    $pdo->prepare("INSERT INTO activities (project_id, activity_name, planned_start, planned_budget, status) VALUES (?,?,?,?,'ongoing')")
        ->execute([$p4, 'จัดซื้อระบบประชุมทางไกลแพทย์เฉพาะทาง', (clone $today)->modify('+5 days')->format('Y-m-d'), 1000000]);
    $a7 = $pdo->lastInsertId();
    seedActivityPhases($pdo, $a7, (clone $today)->modify('+5 days')->format('Y-m-d'), false);
    
    $pdo->prepare("INSERT INTO activities (project_id, activity_name, planned_start, planned_budget, status) VALUES (?,?,?,?,'planned')")
        ->execute([$p4, 'ติดตั้งอุปกรณ์ที่ รพ.สต. 10 แห่ง', (clone $today)->modify('+40 days')->format('Y-m-d'), 500000]);
    $a8 = $pdo->lastInsertId();
    seedActivityPhases($pdo, $a8, (clone $today)->modify('+40 days')->format('Y-m-d'), false);

    $pdo->prepare("INSERT INTO activities (project_id, activity_name, planned_start, planned_budget, status) VALUES (?,?,?,?,'planned')")
        ->execute([$p4, 'อบรมเจ้าหน้าที่สาธารณสุขการใช้แอปพลิเคชัน', (clone $today)->modify('+60 days')->format('Y-m-d'), 300000]);
    $a9 = $pdo->lastInsertId();
    seedActivityPhases($pdo, $a9, (clone $today)->modify('+60 days')->format('Y-m-d'), false);

    $pdo->prepare("INSERT INTO activities (project_id, activity_name, planned_start, planned_budget, status) VALUES (?,?,?,?,'planned')")
        ->execute([$p4, 'ทดสอบระบบกับผู้ป่วยจำลอง', (clone $today)->modify('+90 days')->format('Y-m-d'), 150000]);
    $a10 = $pdo->lastInsertId();
    seedActivityPhases($pdo, $a10, (clone $today)->modify('+90 days')->format('Y-m-d'), false);

    // ----------- PROJECT 5: ELDERLY DEPRESSION (5 Activities) -----------
    $p5Start = (clone $today)->modify('+30 days')->format('Y-m-d');
    $pdo->prepare("INSERT INTO projects (user_id, title, budget_total, start_date, status) VALUES (?,?,?,?,'active')")
        ->execute([4, 'โครงการป้องกันภาวะซึมเศร้าในผู้สูงอายุ', 900000, $p5Start]);
    $p5 = $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO project_phases (project_id, phase_number, phase_name, deadline_date, status, completed_date) VALUES (?,1,'ขออนุมัติโครงการ',?,'completed',?)")
        ->execute([$p5, (clone $today)->modify('-2 days')->format('Y-m-d'), (clone $today)->modify('-1 days')->format('Y-m-d')]);
    $pdo->prepare("INSERT INTO project_phases (project_id, phase_number, phase_name, deadline_date, status) VALUES (?,2,'สรุปโครงการ',?,'pending')")
        ->execute([$p5, (clone $today)->modify('+150 days')->format('Y-m-d')]);

    for ($i=1; $i<=5; $i++) {
        $pdo->prepare("INSERT INTO activities (project_id, activity_name, planned_start, planned_budget, status) VALUES (?,?,?,?,'planned')")
            ->execute([$p5, "จัดกิจกรรมนันทนาการและสันทนาการชุมชน ครั้งที่ $i", (clone $today)->modify("+".(20+$i*15)." days")->format('Y-m-d'), 150000]);
        $act_id = $pdo->lastInsertId();
        seedActivityPhases($pdo, $act_id, (clone $today)->modify("+".(20+$i*15)." days")->format('Y-m-d'), false);
    }

    // ----------- PROJECT 6: ART THERAPY (3 Activities) -----------
    $p6Start = (clone $today)->modify('-120 days')->format('Y-m-d');
    $pdo->prepare("INSERT INTO projects (user_id, title, budget_total, start_date, status) VALUES (?,?,?,?,'completed')")
        ->execute([3, 'โครงการศิลปะบำบัดสำหรับเด็กพิเศษ', 300000, $p6Start]);
    $p6 = $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO project_phases (project_id, phase_number, phase_name, deadline_date, status, completed_date) VALUES (?,1,'ขออนุมัติโครงการ',?,'completed',?)")
        ->execute([$p6, (clone $today)->modify('-125 days')->format('Y-m-d'), (clone $today)->modify('-124 days')->format('Y-m-d')]);
    $pdo->prepare("INSERT INTO project_phases (project_id, phase_number, phase_name, deadline_date, status, completed_date) VALUES (?,2,'สรุปโครงการ',?,'completed',?)")
        ->execute([$p6, (clone $today)->modify('-10 days')->format('Y-m-d'), (clone $today)->modify('-5 days')->format('Y-m-d')]);

    for ($i=1; $i<=3; $i++) {
        $pdo->prepare("INSERT INTO activities (project_id, activity_name, planned_start, planned_budget, status) VALUES (?,?,?,?,'completed')")
            ->execute([$p6, "กิจกรรมระบายสีสื่ออารมณ์ กลุ่ม $i", (clone $today)->modify("-".(100 - $i*20)." days")->format('Y-m-d'), 90000]);
        $act_id = $pdo->lastInsertId();
        seedActivityPhases($pdo, $act_id, (clone $today)->modify("-".(100 - $i*20)." days")->format('Y-m-d'), true);
        $pdo->prepare("INSERT INTO activity_reports (activity_id, report_date, participants, budget_spent, summary, reported_by) VALUES (?,?,?,?,?,?)")
            ->execute([$act_id, (clone $today)->modify("-".(95 - $i*20)." days")->format('Y-m-d'), 40, 85000, "เด็กๆ ตอบสนองต่อกิจกรรมศิลปะเป็นอย่างดี", 3]);
    }

    // ----------- PROJECT 7: DISASTER RELIEF (1 Activity) -----------
    $p7Start = (clone $today)->modify('-30 days')->format('Y-m-d');
    $pdo->prepare("INSERT INTO projects (user_id, title, budget_total, start_date, status) VALUES (?,?,?,?,'active')")
        ->execute([4, 'โครงการศูนย์พึ่งพิงทางใจสำหรับผู้ประสบภัย', 600000, $p7Start]);
    $p7 = $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO project_phases (project_id, phase_number, phase_name, deadline_date, status, completed_date) VALUES (?,1,'ขออนุมัติโครงการ',?,'completed',?)")
        ->execute([$p7, (clone $today)->modify('-35 days')->format('Y-m-d'), (clone $today)->modify('-34 days')->format('Y-m-d')]);
    $pdo->prepare("INSERT INTO project_phases (project_id, phase_number, phase_name, deadline_date, status) VALUES (?,2,'สรุปโครงการ',?,'pending')")
        ->execute([$p7, (clone $today)->modify('+60 days')->format('Y-m-d')]);

    $pdo->prepare("INSERT INTO activities (project_id, activity_name, planned_start, planned_budget, status) VALUES (?,?,?,?,'ongoing')")
        ->execute([$p7, 'หน่วยปฐมพยาบาลจิตวิทยาฉุกเฉิน (PFA)', (clone $today)->modify('-25 days')->format('Y-m-d'), 500000]);
    $act_id = $pdo->lastInsertId();
    seedActivityPhases($pdo, $act_id, (clone $today)->modify('-25 days')->format('Y-m-d'), false);
    $pdo->prepare("UPDATE activity_phases SET status='overdue' WHERE activity_id=? AND phase_number=4")
        ->execute([$act_id]);

    $pdo->commit();
    echo "✅ Seeded new 2-level demo data successfully!";
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "❌ Error: " . $e->getMessage();
}
