/**
 * bg_orbs.js — Fond animé avec orbes lumineuses flottantes
 * Usage : <canvas id="bgCanvas"></canvas> + <script src="js/bg_orbs.js"></script>
 * Options : window.BG_ORBS_COUNT (défaut 7), window.BG_ORBS_DARK (défaut true)
 */
(function(){
    var c = document.getElementById('bgCanvas');
    if (!c) return;
    var x = c.getContext('2d');
    var count = window.BG_ORBS_COUNT || 7;
    var dark = window.BG_ORBS_DARK !== undefined ? window.BG_ORBS_DARK : true;

    function resize(){ c.width = window.innerWidth; c.height = window.innerHeight; }
    resize();
    window.addEventListener('resize', resize);

    var orbs = [];
    for (var i = 0; i < count; i++) {
        orbs.push({
            x: Math.random() * c.width,
            y: Math.random() * c.height,
            r: 80 + Math.random() * 180,
            vx: (Math.random() - 0.5) * 0.4,
            vy: (Math.random() - 0.5) * 0.3,
            ax: 0, ay: 0,
            hue: 180 + Math.random() * 40,
            sat: 30 + Math.random() * 30,
            alpha: dark ? (0.04 + Math.random() * 0.06) : (0.06 + Math.random() * 0.08),
            lightness: dark ? 70 : 55,
            pulse: Math.random() * Math.PI * 2,
            pulseSpeed: 0.005 + Math.random() * 0.01
        });
    }

    function draw() {
        x.clearRect(0, 0, c.width, c.height);
        var cx = c.width / 2, cy = c.height / 2;

        orbs.forEach(function(o) {
            // Random direction changes
            if (Math.random() < 0.02) {
                o.ax = (Math.random() - 0.5) * 0.03;
                o.ay = (Math.random() - 0.5) * 0.02;
            }
            o.vx += o.ax;
            o.vy += o.ay;

            // Gentle pull toward center
            o.vx += (cx - o.x) * 0.00002;
            o.vy += (cy - o.y) * 0.00002;

            // Damping
            o.vx *= 0.998;
            o.vy *= 0.998;

            // Speed limit
            var sp = Math.sqrt(o.vx * o.vx + o.vy * o.vy);
            if (sp > 0.6) { o.vx *= 0.6 / sp; o.vy *= 0.6 / sp; }

            o.x += o.vx;
            o.y += o.vy;

            // Wrap around
            if (o.x < -o.r) o.x = c.width + o.r;
            if (o.x > c.width + o.r) o.x = -o.r;
            if (o.y < -o.r) o.y = c.height + o.r;
            if (o.y > c.height + o.r) o.y = -o.r;

            // Pulse
            o.pulse += o.pulseSpeed;
            var pr = o.r * (0.9 + 0.2 * Math.sin(o.pulse));
            var pa = o.alpha * (0.7 + 0.3 * Math.sin(o.pulse * 1.3));

            // Draw gradient orb
            var g = x.createRadialGradient(o.x, o.y, 0, o.x, o.y, pr);
            g.addColorStop(0, 'hsla(' + o.hue + ',' + o.sat + '%,' + o.lightness + '%,' + pa + ')');
            g.addColorStop(0.5, 'hsla(' + o.hue + ',' + o.sat + '%,' + (o.lightness - 15) + '%,' + (pa * 0.5) + ')');
            g.addColorStop(1, 'hsla(' + o.hue + ',' + o.sat + '%,' + (o.lightness - 30) + '%,0)');
            x.fillStyle = g;
            x.beginPath();
            x.arc(o.x, o.y, pr, 0, Math.PI * 2);
            x.fill();
        });

        requestAnimationFrame(draw);
    }
    draw();
})();
