<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student QR Generator | ACES</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { background: #dcf3e6; display: flex; align-items: center; justify-content: center; min-height: 100vh; font-family: 'Segoe UI', sans-serif; }
        .id-card { background: white; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.15); width: 100%; max-width: 400px; overflow: hidden; }
        .card-header-custom { background: #0a6e2d; color: white; padding: 20px; text-align: center; }
        .qr-container { background: #f8f9fa; border: 2px dashed #cbd5e1; border-radius: 15px; padding: 20px; margin: 20px 0; text-align: center; }
    </style>
</head>
<body>

<div class="id-card">
    <div class="card-header-custom">
        <h4 class="mb-0"><i class="fas fa-id-badge"></i> ACES Digital ID</h4>
        <p class="mb-0 text-light" style="font-size: 12px;">Kolehiyo ng Lungsod ng Dasmariñas</p>
    </div>
    
    <div class="p-4">
        <div class="mb-3">
            <label class="form-label fw-bold text-muted" style="font-size: 12px;">Enter Student ID to Generate</label>
            <div class="input-group">
                <input type="text" id="student_id_input" class="form-control form-control-lg font-monospace" placeholder="e.g. 2023-1-99001" value="2023-1-99001">
                <button class="btn btn-success" onclick="generateQR()">Generate</button>
            </div>
        </div>

        <div class="qr-container" id="qr_container" style="display: none;">
            <img id="qr_image" src="" alt="Student QR Code" class="img-fluid rounded" style="max-width: 200px;">
            <p class="mt-2 mb-0 font-monospace fw-bold text-dark" id="display_id_text"></p>
        </div>

        <div class="text-center mt-3">
            <p class="text-muted" style="font-size: 11px;"><i class="fas fa-info-circle"></i> This simulates the student's physical ID. Hold the QR code to the Proctor's camera.</p>
        </div>
    </div>
</div>

<script>
    function generateQR() {
        const studentId = document.getElementById('student_id_input').value.trim();
        if (!studentId) {
            alert('Please enter a Student ID');
            return;
        }

        // We use a free API to generate the QR code image instantly
        const qrUrl = `https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=${encodeURIComponent(studentId)}`;
        
        document.getElementById('qr_image').src = qrUrl;
        document.getElementById('display_id_text').textContent = studentId;
        document.getElementById('qr_container').style.display = 'block';
    }

    // Generate on page load automatically
    window.onload = generateQR;
</script>

</body>
</html>