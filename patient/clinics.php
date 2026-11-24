<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

// Only logged-in patients can browse clinics.
requireRole('patient');

// Fetch verified clinics from the database.
$sql = "SELECT clinic_id, name, county, address,
               contact_phone, operating_hours
        FROM clinics
        WHERE verification_status = 'verified'
        ORDER BY name ASC";

$result = $conn->query($sql);

if (!$result) {
    error_log("Clinic query failed: " . $conn->error);
}

$pageTitle = "Browse Clinics";
$activeNav = "clinics";
require_once __DIR__ . '/../includes/patient_layout_header.php';
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1 class="page-title">Verified Clinics</h1>
        <p class="page-subtitle">Browse clinics that have been verified on HealthConnect.</p>
    </div>
    <a href="dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
</div>

<?php if (!$result): ?>
    <div class="alert alert-error">
        We could not load the clinics at the moment. Please try again.
    </div>
<?php elseif ($result->num_rows > 0): ?>
    <div class="stats-grid">
        <?php while ($clinic = $result->fetch_assoc()): ?>
            <div class="card" style="display: flex; flex-direction: column; justify-content: space-between;">
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 0.5rem; margin-bottom: 0.75rem;">
                        <h3 style="margin: 0; color: var(--primary);">
                            <?= htmlspecialchars($clinic['name'], ENT_QUOTES, 'UTF-8') ?>
                        </h3>
                        <span class="badge badge-success">Verified</span>
                    </div>

                    <p style="margin-bottom: 0.4rem; font-size: 0.95rem;">
                        <strong>County:</strong>
                        <?= htmlspecialchars($clinic['county'], ENT_QUOTES, 'UTF-8') ?>
                    </p>

                    <p style="margin-bottom: 0.4rem; font-size: 0.95rem;">
                        <strong>Address:</strong>
                        <?= htmlspecialchars($clinic['address'], ENT_QUOTES, 'UTF-8') ?>
                    </p>

                    <p style="margin-bottom: 0.4rem; font-size: 0.95rem;">
                        <strong>Operating Hours:</strong>
                        <?= htmlspecialchars($clinic['operating_hours'] ?? 'Not provided', ENT_QUOTES, 'UTF-8') ?>
                    </p>

                    <?php if (!empty($clinic['contact_phone'])): ?>
                        <p style="margin-bottom: 1rem; font-size: 0.95rem;">
                            <strong>Contact:</strong>
                            <?= htmlspecialchars($clinic['contact_phone'], ENT_QUOTES, 'UTF-8') ?>
                        </p>
                    <?php endif; ?>
                </div>

                <div style="margin-top: 0.75rem;">
                    <a href="slots.php?clinic_id=<?= (int) $clinic['clinic_id'] ?>" class="btn btn-primary">
                        View Available Slots
                    </a>
                </div>
            </div>
        <?php endwhile; ?>
    </div>
<?php else: ?>
    <div class="card" style="text-align: center; padding: 2rem;">
        <p style="color: var(--text-muted);">No verified clinics are currently available.</p>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/patient_layout_footer.php'; ?>