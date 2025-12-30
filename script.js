let scene,
  camera,
  renderer,
  planet,
  planetGlow,
  nebulae,
  stars,
  shootingStars = [],
  head,
  feathers = [],
  auroraRibbons = [],
  swirlParticles = [],
  floatingCrystals = [],
  portal = null,
  constellationLines = [],
  comet = null,
  cometTrail = null,
  cometTimeout = 0;
let mouseX = 0,
  mouseY = 0;
let windowHalfX = window.innerWidth / 2;
let windowHalfY = window.innerHeight / 2;
let panelFloatPhase = 0;

// Performance scaling
let EFFECT_SCALE = 1.0;

// Add at the top:
const EARTH_TEXTURE_URL =
  'https://upload.wikimedia.org/wikipedia/commons/9/97/The_Earth_seen_from_Apollo_17.jpg'; // NASA public domain
const NEBULA_TEXTURE_URL =
  'https://cdn.jsdelivr.net/gh/akabab/space-art-assets@main/nebulae/nebula-1.jpg'; // Free nebula texture

// Initialize the application
document.addEventListener('DOMContentLoaded', function () {
  initThreeJS();
  initParticles();
  initEventListeners();
  initAurora();

  // Hide loading screen after everything is ready
  setTimeout(() => {
    const loadingScreen = document.getElementById('loadingScreen');
    loadingScreen.classList.add('fade-out');
    setTimeout(() => {
      loadingScreen.style.display = 'none';
    }, 500);
  }, 2000);

  animate();
});

// Three.js Initialization
function initThreeJS() {
  const canvas = document.getElementById('spaceCanvas');

  // Scene setup
  scene = new THREE.Scene();

  // Camera setup
  camera = new THREE.PerspectiveCamera(
    75,
    window.innerWidth / window.innerHeight,
    0.1,
    1000
  );
  camera.position.z = 5;

  // Renderer setup
  renderer = new THREE.WebGLRenderer({
    canvas: canvas,
    antialias: true,
    alpha: true,
  });
  renderer.setSize(window.innerWidth, window.innerHeight);
  renderer.setClearColor(0x000000, 0);

  // Performance: set pixel ratio
  renderer.setPixelRatio(window.devicePixelRatio);
  // Performance: detect slow device
  if (navigator.hardwareConcurrency && navigator.hardwareConcurrency <= 4) {
    EFFECT_SCALE = 0.6;
  } else if (/Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent)) {
    EFFECT_SCALE = 0.5;
  }

  // Create stars
  createStars();

  // Create shooting stars
  createShootingStars();

  // Add ambient light
  const ambientLight = new THREE.AmbientLight(0x404040, 0.3);
  scene.add(ambientLight);

  // Add directional light
  const directionalLight = new THREE.DirectionalLight(0x4facfe, 0.8);
  directionalLight.position.set(5, 5, 5);
  scene.add(directionalLight);

  // Create head with feathers
  createHeadWithFeathers();

  // Add nebula/gradient background
  addNebulaBackground();

  // Add aurora ribbons
  createAuroraRibbons();

  // Add swirling particles
  createSwirlParticles();

  // Add floating crystals
  createFloatingCrystals();

  // Add swirling portal
  createSwirlingPortal();

  // Add constellation lines
  createConstellationLines();
}

// Create star field
function createStars() {
  const starsGeometry = new THREE.BufferGeometry();
  const starsMaterial = new THREE.PointsMaterial({
    vertexColors: true,
    size: 0.12,
    transparent: true,
    opacity: 0.85,
  });

  const starsVertices = [];
  const starsColors = [];
  for (let i = 0; i < Math.floor(1200 * EFFECT_SCALE); i++) {
    const x = (Math.random() - 0.5) * 120;
    const y = (Math.random() - 0.5) * 120;
    const z = (Math.random() - 0.5) * 120;
    starsVertices.push(x, y, z);
    const color = new THREE.Color(`hsl(${Math.random() * 360}, 80%, 80%)`);
    starsColors.push(color.r, color.g, color.b);
  }

  starsGeometry.setAttribute(
    'position',
    new THREE.Float32BufferAttribute(starsVertices, 3)
  );
  starsGeometry.setAttribute(
    'color',
    new THREE.Float32BufferAttribute(starsColors, 3)
  );
  stars = new THREE.Points(starsGeometry, starsMaterial);
  scene.add(stars);
}

// Create shooting stars (make them thinner and more transparent)
function createShootingStars() {
  for (let i = 0; i < Math.floor(3 * EFFECT_SCALE); i++) {
    const geometry = new THREE.CylinderGeometry(0.005, 0.08, 1.5, 8, 1, true);
    const material = new THREE.MeshBasicMaterial({
      color: 0xffffff,
      transparent: true,
      opacity: 0.25,
    });
    const shootingStar = new THREE.Mesh(geometry, material);
    resetShootingStar(shootingStar);
    scene.add(shootingStar);
    shootingStars.push(shootingStar);
  }
}

function resetShootingStar(star) {
  star.position.x = Math.random() * 30 - 15;
  star.position.y = Math.random() * 10 + 8;
  star.position.z = Math.random() * -10 - 5;
  star.rotation.z = Math.PI / 4 + Math.random() * 0.2;
  star.visible = Math.random() > 0.5;
}

// Create floating particles
function initParticles() {
  const particlesContainer = document.getElementById('particles');

  for (let i = 0; i < Math.floor(50 * EFFECT_SCALE); i++) {
    const particle = document.createElement('div');
    particle.className = 'particle';
    particle.style.left = Math.random() * 100 + '%';
    particle.style.top = Math.random() * 100 + '%';
    particle.style.animationDelay = Math.random() * 6 + 's';
    particle.style.animationDuration = Math.random() * 3 + 3 + 's';
    particlesContainer.appendChild(particle);
  }
}

// Initialize event listeners
function initEventListeners() {
  // Mouse movement for parallax effect
  document.addEventListener('mousemove', onDocumentMouseMove);

  // Window resize
  window.addEventListener('resize', onWindowResize);

  // Form submission
  const loginForm = document.getElementById('loginForm');
  loginForm.addEventListener('submit', handleLogin);

  // Input focus effects
  const inputs = document.querySelectorAll('input');
  inputs.forEach((input) => {
    input.addEventListener('focus', handleInputFocus);
    input.addEventListener('blur', handleInputBlur);
    input.addEventListener('input', handleInputType);
  });

  // Button hover effects
  const loginBtn = document.getElementById('loginBtn');
  loginBtn.addEventListener('mouseenter', handleButtonHover);
  loginBtn.addEventListener('mouseleave', handleButtonLeave);
  loginBtn.addEventListener('click', createButtonParticles);

  // Link hover effects
  const links = document.querySelectorAll('.hologram-link');
  links.forEach((link) => {
    link.addEventListener('mouseenter', handleLinkHover);
  });
}

// Mouse movement handler
function onDocumentMouseMove(event) {
  mouseX = (event.clientX - windowHalfX) / 100;
  mouseY = (event.clientY - windowHalfY) / 100;
}

// Window resize handler
function onWindowResize() {
  windowHalfX = window.innerWidth / 2;
  windowHalfY = window.innerHeight / 2;

  camera.aspect = window.innerWidth / window.innerHeight;
  camera.updateProjectionMatrix();
  renderer.setSize(window.innerWidth, window.innerHeight);
}

// Input focus handler
function handleInputFocus(event) {
  const input = event.target;
  const wrapper = input.closest('.input-wrapper');
  const wave = wrapper.querySelector('.voicewave');

  // Show voicewave animation
  if (wave) {
    wave.classList.add('active');
    createVoicewave(wave);
  }

  // Add floating effect
  wrapper.style.transform = 'translateY(-5px)';
}

// Input blur handler
function handleInputBlur(event) {
  const input = event.target;
  const wrapper = input.closest('.input-wrapper');
  const wave = wrapper.querySelector('.voicewave');

  // Hide voicewave animation
  if (wave) {
    wave.classList.remove('active');
    wave.innerHTML = '';
  }

  // Remove floating effect
  wrapper.style.transform = 'translateY(0)';
}

// Input typing handler
function handleInputType(event) {
  const input = event.target;
  const wrapper = input.closest('.input-wrapper');
  const wave = wrapper.querySelector('.voicewave');

  // Update voicewave animation
  if (wave && wave.classList.contains('active')) {
    wave.innerHTML = '';
    createVoicewave(wave);
  }
}

// Create voicewave animation
function createVoicewave(waveElement) {
  for (let i = 0; i < 5; i++) {
    const bar = document.createElement('div');
    bar.className = 'voicewave-bar';
    waveElement.appendChild(bar);
  }
}

// Button hover handler
function handleButtonHover(event) {
  const button = event.target;
  button.style.transform = 'translateY(-2px) scale(1.02)';

  // Play hover sound
  const hoverSound = document.getElementById('hoverSound');
  if (hoverSound) {
    hoverSound.currentTime = 0;
    hoverSound.play().catch(() => {}); // Ignore autoplay restrictions
  }
}

// Button leave handler
function handleButtonLeave(event) {
  const button = event.target;
  button.style.transform = 'translateY(0) scale(1)';
}

// Create button particles
function createButtonParticles(event) {
  const button = event.target;
  const particlesContainer = button.querySelector('.btn-particles');

  for (let i = 0; i < 10; i++) {
    const particle = document.createElement('div');
    particle.className = 'btn-particle';
    particle.style.left = '50%';
    particle.style.top = '50%';
    particle.style.setProperty('--x', (Math.random() - 0.5) * 100 + 'px');
    particle.style.setProperty('--y', (Math.random() - 0.5) * 100 + 'px');
    particlesContainer.appendChild(particle);

    setTimeout(() => {
      particle.remove();
    }, 1000);
  }
}

// Link hover handler
function handleLinkHover(event) {
  const link = event.target;

  // Create ripple effect
  const ripple = document.createElement('div');
  ripple.style.position = 'absolute';
  ripple.style.width = '100%';
  ripple.style.height = '100%';
  ripple.style.background =
    'radial-gradient(circle, rgba(79, 172, 254, 0.3) 0%, transparent 70%)';
  ripple.style.borderRadius = '50%';
  ripple.style.transform = 'scale(0)';
  ripple.style.animation = 'ripple 0.6s ease-out';
  ripple.style.pointerEvents = 'none';

  link.style.position = 'relative';
  link.appendChild(ripple);

  setTimeout(() => {
    ripple.remove();
  }, 600);
}

// Initialize aurora effect
function initAurora() {
  const aurora = document.createElement('div');
  aurora.className = 'aurora';
  document.body.appendChild(aurora);
}

// Handle login form submission
function handleLogin(event) {
  event.preventDefault();

  const formData = new FormData(event.target);
  const username = formData.get('username');
  const password = formData.get('password');

  // Show loading state
  const loginBtn = document.getElementById('loginBtn');
  const btnText = loginBtn.querySelector('.btn-text');
  btnText.textContent = 'Connecting...';
  loginBtn.disabled = true;

  // Simulate login process
  setTimeout(() => {
    if (username === 'admin' && password === 'password') {
      // Success - trigger hyperspace transition
      triggerHyperspaceTransition();
    } else {
      // Error - show glitch effect
      showLoginError('Invalid credentials. Access denied.');
      btnText.textContent = 'Login';
      loginBtn.disabled = false;
    }
  }, 2000);
}

// Show login error with glitch effect
function showLoginError(message) {
  const errorElement = document.getElementById('errorMessage');
  errorElement.textContent = message;
  errorElement.classList.add('show', 'glitch');

  // Remove glitch effect after animation
  setTimeout(() => {
    errorElement.classList.remove('glitch');
  }, 300);

  // Hide error after 5 seconds
  setTimeout(() => {
    errorElement.classList.remove('show');
  }, 5000);
}

// Trigger hyperspace transition
function triggerHyperspaceTransition() {
  // Create hyperspace overlay
  const hyperspace = document.createElement('div');
  hyperspace.className = 'hyperspace';
  document.body.appendChild(hyperspace);

  // Activate hyperspace effect
  setTimeout(() => {
    hyperspace.classList.add('active');
  }, 100);

  // Zoom effect on login panel
  const loginPanel = document.getElementById('loginPanel');
  gsap.to(loginPanel, {
    scale: 0.1,
    duration: 1,
    ease: 'power2.in',
  });

  // After transition, redirect or show success
  setTimeout(() => {
    // You can redirect to a dashboard here
    // window.location.href = 'dashboard.php';

    // For demo, show success message
    showSuccessMessage();

    // Reset hyperspace effect
    hyperspace.classList.remove('active');
    setTimeout(() => {
      hyperspace.remove();
    }, 500);

    // Reset login panel
    gsap.to(loginPanel, {
      scale: 1,
      duration: 0.5,
      ease: 'power2.out',
    });

    // Reset button
    const loginBtn = document.getElementById('loginBtn');
    const btnText = loginBtn.querySelector('.btn-text');
    btnText.textContent = 'Login';
    loginBtn.disabled = false;
  }, 2000);
}

// Show success message
function showSuccessMessage() {
  const errorElement = document.getElementById('errorMessage');
  errorElement.textContent = 'Access granted! Welcome to the cosmos.';
  errorElement.style.color = '#4facfe';
  errorElement.classList.add('show');

  setTimeout(() => {
    errorElement.classList.remove('show');
  }, 3000);
}

// Animation loop
function animate() {
  requestAnimationFrame(animate);

  // Animate stars
  if (stars) {
    stars.rotation.y += 0.0007;
    stars.position.x = mouseX * 0.3;
    stars.position.y = mouseY * 0.3;

    // Twinkle effect
    if (stars.material && stars.material.opacity) {
      stars.material.opacity =
        0.7 + 0.3 * Math.abs(Math.sin(Date.now() * 0.001));
    }
  }

  // Animate shooting stars
  shootingStars.forEach((star) => {
    if (star.visible) {
      star.position.x += 0.25;
      star.position.y -= 0.12;
      if (star.position.x > 20 || star.position.y < -10) {
        resetShootingStar(star);
      }
    } else if (Math.random() < 0.002) {
      star.visible = true;
    }
  });

  // Floating animation for login panel and orb - DISABLED
  // panelFloatPhase += 0.01;
  // const loginPanel = document.getElementById('loginPanel');
  // const crystalOrb = document.querySelector('.crystal-orb');
  // if (loginPanel)
  //   loginPanel.style.transform = `translateY(-5px) scale(1.01) translateZ(0) translateX(0) skewY(${
  //     Math.sin(panelFloatPhase) * 1.5
  //   }deg)`;
  // if (crystalOrb)
  //   crystalOrb.style.transform = `translateY(${
  //     Math.sin(panelFloatPhase) * 8
  //   }px) scale(1.03)`;

  // Animate head and feathers
  if (head) {
    // Rotate head to follow mouse
    head.rotation.y = mouseX * 0.5;
    head.rotation.x = mouseY * 0.3;
    // Animate feathers
    feathers.forEach((feather, i) => {
      const t = Date.now() * 0.001 + i;
      feather.rotation.z = Math.sin(t) * 0.2 + (i - feathers.length / 2) * 0.15;
      feather.rotation.y = mouseX * 0.5;
      feather.rotation.x = mouseY * 0.3;
    });
  }

  // Animate aurora ribbons
  auroraRibbons.forEach((ribbon, i) => {
    const t = Date.now() * 0.0007 + i * 0.5;
    for (let v = 0; v < ribbon.geometry.attributes.position.count; v++) {
      const y =
        Math.sin(t + v * 0.2 + i) * 0.5 + Math.cos(t * 0.7 + v * 0.3) * 0.2;
      ribbon.geometry.attributes.position.setY(v, y);
    }
    ribbon.geometry.attributes.position.needsUpdate = true;
    ribbon.material.opacity = 0.25 + 0.15 * Math.abs(Math.sin(t * 1.2 + i));
  });

  // Animate swirl particles
  swirlParticles.forEach((particle, i) => {
    const t = Date.now() * 0.0005 + i;
    particle.userData.angle += particle.userData.speed;
    particle.position.x =
      Math.cos(particle.userData.angle) * particle.userData.radius;
    particle.position.z =
      Math.sin(particle.userData.angle) * particle.userData.radius;
    particle.position.y =
      particle.userData.yBase +
      Math.sin(t * particle.userData.ySpeed) * particle.userData.yAmp;
  });

  // Animate floating crystals
  floatingCrystals.forEach((crystal, i) => {
    crystal.rotation.y += crystal.userData.rotSpeed;
    crystal.rotation.x += crystal.userData.rotSpeed * 0.7;
    crystal.position.y +=
      Math.sin(Date.now() * 0.001 + crystal.userData.floatPhase) *
      0.008 *
      crystal.userData.floatAmp;
  });

  // Animate portal
  if (portal) {
    portal.rotation.z += 0.008;
    portal.material.color.setHSL((Date.now() * 0.0001) % 1, 0.7, 0.7);
    portal.material.opacity =
      0.35 + 0.15 * Math.abs(Math.sin(Date.now() * 0.001));
  }

  // Parallax nebula
  if (nebulae) {
    nebulae.rotation.y = mouseX * 0.08;
    nebulae.rotation.x = mouseY * 0.04;
  }

  // Animate constellation lines (fade in/out)
  constellationLines.forEach((line, i) => {
    const t = Date.now() * 0.0005 + i;
    line.material.opacity = 0.7 * Math.abs(Math.sin(t));
  });

  // Occasionally regenerate constellation lines
  if (Math.random() < 0.003) {
    createConstellationLines();
  }

  // Animate comet
  if (comet) {
    comet.position.x += 0.22;
    comet.position.y -= 0.09;
    cometTrail.position.x = comet.position.x - 1.2;
    cometTrail.position.y = comet.position.y - 0.5;
    if (
      comet.position.x > 20 ||
      comet.position.y < -10 ||
      Date.now() > cometTimeout
    ) {
      scene.remove(comet);
      scene.remove(cometTrail);
      comet = null;
      cometTrail = null;
    }
  } else if (Math.random() < 0.002) {
    spawnComet();
  }

  // Portal pulse
  if (portal) {
    portal.scale.setScalar(1 + 0.08 * Math.sin(Date.now() * 0.004));
  }

  // Nebula color shift
  if (nebulae && nebulae.material && nebulae.material.color) {
    const t = Date.now() * 0.00008;
    nebulae.material.color.setHSL(0.58 + 0.08 * Math.sin(t), 0.7, 0.7);
  }

  renderer.render(scene, camera);
}

// Add ripple animation to CSS
const style = document.createElement('style');
style.textContent = `
    @keyframes ripple {
        0% { transform: scale(0); opacity: 1; }
        100% { transform: scale(4); opacity: 0; }
    }
`;
document.head.appendChild(style);

// Device orientation support (for mobile)
if (window.DeviceOrientationEvent) {
  window.addEventListener('deviceorientation', function (event) {
    const tiltX = event.beta / 90; // -1 to 1
    const tiltY = event.gamma / 90; // -1 to 1

    mouseX = tiltY * 2;
    mouseY = tiltX * 2;
  });
}

// Touch support for mobile
document.addEventListener(
  'touchmove',
  function (event) {
    event.preventDefault();
    const touch = event.touches[0];
    mouseX = (touch.clientX - windowHalfX) / 100;
    mouseY = (touch.clientY - windowHalfY) / 100;
  },
  { passive: false }
);

// Performance optimization
let frameCount = 0;
function optimizePerformance() {
  frameCount++;
  if (frameCount % 2 === 0) {
    // Reduce particle count on slower devices
    const particles = document.querySelectorAll('.particle');
    if (particles.length > 25) {
      for (let i = 25; i < particles.length; i++) {
        particles[i].style.display = 'none';
      }
    }
  }
}

// Call performance optimization
setInterval(optimizePerformance, 1000);

// Add this function:
function createHeadWithFeathers() {
  // Head (black sphere)
  const headGeometry = new THREE.SphereGeometry(1.2, 32, 32);
  const headMaterial = new THREE.MeshPhongMaterial({
    color: 0x111111,
    shininess: 60,
  });
  head = new THREE.Mesh(headGeometry, headMaterial);
  head.position.set(-7, -1, -5);
  scene.add(head);

  // Feathers (curved tubes)
  const featherCount = 18;
  for (let i = 0; i < featherCount; i++) {
    const angle = Math.PI * 1.2 * (i / (featherCount - 1)) - Math.PI * 0.6;
    const curve = new THREE.CatmullRomCurve3([
      new THREE.Vector3(0, 1.1, 0),
      new THREE.Vector3(Math.sin(angle) * 1.5, 2.5, Math.cos(angle) * 1.5),
      new THREE.Vector3(Math.sin(angle) * 3, 5, Math.cos(angle) * 3),
    ]);
    const tubeGeometry = new THREE.TubeGeometry(curve, 32, 0.07, 8, false);
    const tubeMaterial = new THREE.MeshPhongMaterial({
      color: 0x222222,
      shininess: 80,
      transparent: true,
      opacity: 0.7,
    });
    const feather = new THREE.Mesh(tubeGeometry, tubeMaterial);
    feather.position.copy(head.position);
    scene.add(feather);
    feathers.push(feather);
  }

  // Add aurora ribbons
  createAuroraRibbons();

  // Add swirling particles
  createSwirlParticles();
}

// Add this function:
function addNebulaBackground() {
  const geometry = new THREE.SphereGeometry(60, 64, 64);
  const texture = new THREE.TextureLoader().load(NEBULA_TEXTURE_URL);
  const material = new THREE.MeshBasicMaterial({
    map: texture,
    side: THREE.BackSide,
    transparent: true,
    opacity: 0.85,
  });
  const nebula = new THREE.Mesh(geometry, material);
  scene.add(nebula);
  nebulae = nebula;
}

// Add this function:
function createAuroraRibbons() {
  const ribbonCount = Math.max(1, Math.floor(3 * EFFECT_SCALE));
  for (let i = 0; i < ribbonCount; i++) {
    const geometry = new THREE.PlaneGeometry(18, 1.5, 32, 32);
    const material = new THREE.MeshBasicMaterial({
      color: new THREE.Color(`hsl(${180 + i * 40}, 80%, 70%)`),
      transparent: true,
      opacity: 0.35,
      side: THREE.DoubleSide,
    });
    const ribbon = new THREE.Mesh(geometry, material);
    ribbon.position.set(-2 + i * 2, 7 + i * 1.2, -10 - i * 2);
    ribbon.rotation.x = -Math.PI / 2.5 + i * 0.2;
    scene.add(ribbon);
    auroraRibbons.push(ribbon);
  }
}

// Add this function:
function createSwirlParticles() {
  const swirlCount = Math.floor(80 * EFFECT_SCALE);
  for (let i = 0; i < swirlCount; i++) {
    const geometry = new THREE.SphereGeometry(0.07, 8, 8);
    const color = new THREE.Color(`hsl(${Math.random() * 360}, 90%, 70%)`);
    const material = new THREE.MeshBasicMaterial({
      color,
      transparent: true,
      opacity: 0.7,
    });
    const particle = new THREE.Mesh(geometry, material);
    particle.userData = {
      angle: Math.random() * Math.PI * 2,
      radius: 7 + Math.random() * 5,
      speed: 0.002 + Math.random() * 0.003,
      yBase: -2 + Math.random() * 6,
      yAmp: 1 + Math.random() * 2,
      ySpeed: 0.5 + Math.random() * 1.5,
    };
    scene.add(particle);
    swirlParticles.push(particle);
  }
}

// Add this function:
function createFloatingCrystals() {
  const crystalTypes = [
    new THREE.IcosahedronGeometry(0.7, 0),
    new THREE.BoxGeometry(0.8, 0.8, 0.8),
    new THREE.TetrahedronGeometry(0.7, 0),
    new THREE.OctahedronGeometry(0.7, 0),
  ];
  for (let i = 0; i < Math.floor(7 * EFFECT_SCALE); i++) {
    const geometry =
      crystalTypes[Math.floor(Math.random() * crystalTypes.length)].clone();
    const color = new THREE.Color(
      `hsl(${180 + Math.random() * 120}, 80%, 60%)`
    );
    const material = new THREE.MeshPhongMaterial({
      color,
      shininess: 100,
      transparent: true,
      opacity: 0.55,
      emissive: color.clone().multiplyScalar(0.3),
      specular: 0xffffff,
    });
    const mesh = new THREE.Mesh(geometry, material);
    mesh.position.set(
      (Math.random() - 0.5) * 18,
      2 + Math.random() * 8,
      -8 - Math.random() * 10
    );
    mesh.userData = {
      rotSpeed: 0.003 + Math.random() * 0.004,
      floatPhase: Math.random() * Math.PI * 2,
      floatAmp: 0.7 + Math.random() * 0.7,
    };
    // Add glowing outline
    const outlineMaterial = new THREE.MeshBasicMaterial({
      color: 0xffffff,
      side: THREE.BackSide,
      transparent: true,
      opacity: 0.18,
    });
    const outline = new THREE.Mesh(geometry.clone(), outlineMaterial);
    outline.scale.multiplyScalar(1.15);
    mesh.add(outline);
    scene.add(mesh);
    floatingCrystals.push(mesh);
  }
}

// Add this function:
function createSwirlingPortal() {
  const geometry = new THREE.TorusGeometry(3.5, 0.45, 32, 100);
  const material = new THREE.MeshBasicMaterial({
    color: 0x7f7fff,
    transparent: true,
    opacity: 0.45,
    wireframe: true,
  });
  portal = new THREE.Mesh(geometry, material);
  portal.position.set(-2, 4, -18);
  scene.add(portal);
}

// Add this function:
function createConstellationLines() {
  // Remove old lines
  constellationLines.forEach((line) => scene.remove(line));
  constellationLines = [];
  if (!stars) return;
  const positions = stars.geometry.attributes.position.array;
  // Pick 2-4 random pairs
  for (let i = 0; i < 2 + Math.floor(Math.random() * 3); i++) {
    const idxA = Math.floor(Math.random() * 400) * 3;
    const idxB = Math.floor(Math.random() * 400) * 3;
    const geometry = new THREE.BufferGeometry();
    const verts = [
      new THREE.Vector3(
        positions[idxA],
        positions[idxA + 1],
        positions[idxA + 2]
      ),
      new THREE.Vector3(
        positions[idxB],
        positions[idxB + 1],
        positions[idxB + 2]
      ),
    ];
    geometry.setFromPoints(verts);
    const material = new THREE.LineBasicMaterial({
      color: 0x7fffd4,
      transparent: true,
      opacity: 0.0, // will fade in/out
    });
    const line = new THREE.Line(geometry, material);
    scene.add(line);
    constellationLines.push(line);
  }
}

// Add this function:
function spawnComet() {
  if (comet) {
    scene.remove(comet);
    scene.remove(cometTrail);
  }
  const geometry = new THREE.SphereGeometry(0.18, 8, 8);
  const material = new THREE.MeshBasicMaterial({
    color: 0xffffff,
    emissive: 0x7fdfff,
    transparent: true,
    opacity: 0.95,
  });
  comet = new THREE.Mesh(geometry, material);
  comet.position.set(-18, 10 + Math.random() * 6, -12);
  scene.add(comet);
  // Trail
  const trailGeometry = new THREE.CylinderGeometry(0.05, 0.25, 3.5, 8, 1, true);
  const trailMaterial = new THREE.MeshBasicMaterial({
    color: 0x7fdfff,
    transparent: true,
    opacity: 0.45,
  });
  cometTrail = new THREE.Mesh(trailGeometry, trailMaterial);
  cometTrail.position.set(
    comet.position.x,
    comet.position.y - 1.5,
    comet.position.z
  );
  cometTrail.rotation.z = Math.PI / 2.2;
  scene.add(cometTrail);
  cometTimeout = Date.now() + 3500 + Math.random() * 2000;
}
