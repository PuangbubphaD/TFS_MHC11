<?php
require_once __DIR__ . '/includes/header.php';

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrfOrDie();
    $title       = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $budget      = floatval($_POST['budget_total'] ?? 0);
    $start_date  = $_POST['start_date'] ?? '';
    $end_date    = $_POST['end_date'] ?? '';

    if (!$title) {
        $error = 'กรุณากรอกชื่อโครงการ';
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO projects (user_id, title, description, budget_total, start_date, end_date, status)
            VALUES (?, ?, ?, ?, ?, ?, 'active')
        ");
        $stmt->execute([$_SESSION['user_id'], $title, $description, $budget, $start_date ?: null, $end_date ?: null]);
        $project_id = $pdo->lastInsertId();

        // Auto-create 2 administrative phases (Approval & Summary)
        $phases = [
            [1, 'ขออนุมัติโครงการ', 'ยื่นขออนุมัติโครงการและงบประมาณภาพรวม', 0, 'on'],
            [2, 'สรุปโครงการ',      'จัดทำรายงานสรุปผลการดำเนินโครงการภาพรวม', 15, 'after'],
        ];

        foreach ($phases as [$num, $name, $desc, $days, $type]) {
            $deadline_date = null;
            if (!empty($start_date)) {
                $start_dt = new DateTime($start_date);
                if ($type === 'after') {
                    $start_dt->modify("+{$days} days");
                }
                $deadline_date = $start_dt->format('Y-m-d');
            }
            
            $phaseSt = $pdo->prepare("
                INSERT INTO project_phases (project_id, phase_number, phase_name, description, deadline_date, status)
                VALUES (?, ?, ?, ?, ?, 'pending')
            ");
            $phaseSt->execute([$project_id, $num, $name, $desc, $deadline_date]);
        }

        header("Location: view_project.php?id=$project_id&created=1");
        exit;
    }
}
?>

<div class="topbar">
    <div style="display: flex; align-items: center; gap: 0.75rem;">
        <button class="mobile-toggle">☰</button>
        <div>
        <h2>➕ เพิ่มโครงการใหม่</h2>
        <div class="topbar-breadcrumb"><a href="index.php" style="color:var(--text-muted)">โครงการของฉัน</a> / เพิ่มโครงการใหม่</div>
    </div>
    </div>
</div>

<div class="page-content">
    <?php if ($error): ?>
        <div class="alert alert-danger">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="grid grid-2" style="gap:1.5rem;align-items:start">
        <!-- Form -->
        <div class="card fade-in">
            <div class="card-header">
                <h3>📝 ข้อมูลโครงการ</h3>
            </div>
            <form method="POST" action="add_project.php">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                <div class="form-group">
                    <label>ชื่อโครงการ <span class="required">*</span></label>
                    <input type="text" name="title" class="form-control"
                           placeholder="ระบุชื่อโครงการ" required
                           value="<?= htmlspecialchars($_POST['title'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>รายละเอียดโครงการ</label>
                    <textarea name="description" class="form-control" rows="4"
                              placeholder="อธิบายวัตถุประสงค์และรายละเอียดโครงการ"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                </div>
                <div class="form-group">
                    <label>งบประมาณโครงการ (บาท)</label>
                    <input type="number" name="budget_total" class="form-control"
                           placeholder="0.00" step="0.01" min="0"
                           value="<?= htmlspecialchars($_POST['budget_total'] ?? '') ?>">
                </div>
                <div class="form-row form-row-2">
                    <div class="form-group">
                        <label>วันที่เริ่มโครงการ</label>
                        <input type="date" name="start_date" class="form-control"
                               value="<?= htmlspecialchars($_POST['start_date'] ?? '') ?>">
                        <div class="form-hint">หากยังไม่ทราบวันแน่นอน สามารถเว้นว่างไว้ก่อนได้</div>
                    </div>
                    <div class="form-group">
                        <label>วันที่สิ้นสุดโครงการ</label>
                        <input type="date" name="end_date" class="form-control"
                               value="<?= htmlspecialchars($_POST['end_date'] ?? '') ?>">
                    </div>
                </div>
                <div style="display:flex;gap:0.75rem;margin-top:0.5rem">
                    <button type="submit" class="btn btn-primary btn-lg">💾 บันทึกโครงการ</button>
                    <a href="index.php" class="btn btn-outline btn-lg">ยกเลิก</a>
                </div>
            </form>
        </div>

        <!-- Info Panel -->
        <div class="card fade-in" style="background:linear-gradient(135deg,var(--primary-dark),var(--primary));color:#fff">
            <h3 style="color:#fff;margin-bottom:1rem">📌 ขั้นตอนการบริหารโครงการ (2 ระดับ)</h3>
            <p style="color:rgba(255,255,255,0.7);font-size:0.85rem;margin-bottom:1.5rem">
                ระบบ TFS บริหารงานโครงสร้าง 2 ระดับ เพื่อการควบคุมงบประมาณและเวลาปฏิบัติงานอย่างเป็นระบบ:
            </p>
            
            <div style="margin-bottom:1.5rem;display:flex;gap:0.75rem;align-items:flex-start">
                <div style="width:28px;height:28px;background:var(--accent);border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:0.8rem;flex-shrink:0">1</div>
                <div>
                    <div style="font-size:0.9rem;font-weight:700;color:var(--accent-light)">ระดับที่ 1: การบริหารโครงการ (2 ขั้นตอนหลัก)</div>
                    <div style="font-size:0.78rem;color:rgba(255,255,255,0.75);margin-top:0.2rem;line-height:1.5">
                        ระบบจะสร้างขั้นตอน "ขออนุมัติโครงการ" และ "สรุปโครงการ" ให้โดยอัตโนมัติ เพื่ออนุมัติและประเมินผลโครงการในภาพรวม
                    </div>
                </div>
            </div>

            <div style="display:flex;gap:0.75rem;align-items:flex-start">
                <div style="width:28px;height:28px;background:var(--status-green);border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:0.8rem;flex-shrink:0">2</div>
                <div>
                    <div style="font-size:0.9rem;font-weight:700;color:#a7f3d0">ระดับที่ 2: การดำเนินกิจกรรมย่อย (8 ขั้นตอนปฏิบัติ)</div>
                    <div style="font-size:0.78rem;color:rgba(255,255,255,0.75);margin-top:0.2rem;line-height:1.5">
                        เมื่อคุณเพิ่มกิจกรรมย่อยในโครงการ ระบบจะสร้าง 8 ขั้นตอนการปฏิบัติงานตามเกณฑ์มาตรฐาน (ขออนุมัติจัด, ยืมเงิน, เชิญผู้ร่วม, จัดจริง, จัดทำ onepage, เบิกจ่ายงบ, สรุปกิจกรรม, ลงนามอนุมัติสรุป) เพื่อควบคุมไทม์ไลน์และสะสมการเบิกจ่ายงบประมาณจริงในรายกิจกรรม
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
