// portal-effect.js
// Sci-Fi: Electric cyan portal with neon blue/green particles

let scene,
  camera,
  renderer,
  portal,
  particles = [];
let portalAngle = 0;
const PARTICLE_COUNT = 60;
const PORTAL_COLOR = 0x00ffe7; // electric cyan
const AURA_COLOR = 0x00aaff; // neon blue
const PARTICLE_COLORS = [0x00ffe7, 0x00aaff, 0x00ff99, 0xffffff];

function initPortalEffect() {
  const canvas = document.getElementById('portalCanvas');
  if (!canvas) return;

  // Renderer
  renderer = new THREE.WebGLRenderer({ canvas, alpha: true, antialias: true });
  renderer.setSize(window.innerWidth, window.innerHeight);
  renderer.setClearColor(0x000000, 0);

  // Scene
  scene = new THREE.Scene();

  // Camera
  camera = new THREE.PerspectiveCamera(
    60,
    window.innerWidth / window.innerHeight,
    0.1,
    100
  );
  camera.position.z = 8;

  // Portal geometry (torus with glowing material)
  const portalGeometry = new THREE.TorusGeometry(2.2, 0.38, 40, 160);
  const portalMaterial = new THREE.MeshBasicMaterial({
    color: PORTAL_COLOR,
    transparent: true,
    opacity: 0.7,
  });
  portal = new THREE.Mesh(portalGeometry, portalMaterial);
  scene.add(portal);

  // Add glowing aura (slightly larger torus)
  const auraGeometry = new THREE.TorusGeometry(2.2, 0.7, 40, 160);
  const auraMaterial = new THREE.MeshBasicMaterial({
    color: AURA_COLOR,
    transparent: true,
    opacity: 0.18,
  });
  const aura = new THREE.Mesh(auraGeometry, auraMaterial);
  scene.add(aura);

  // Particle streams (sci-fi floating)
  for (let i = 0; i < PARTICLE_COUNT; i++) {
    const particleGeometry = new THREE.SphereGeometry(0.09, 10, 10);
    const color = PARTICLE_COLORS[i % PARTICLE_COLORS.length];
    const particleMaterial = new THREE.MeshBasicMaterial({
      color: color,
      transparent: true,
      opacity: 0.7,
    });
    const particle = new THREE.Mesh(particleGeometry, particleMaterial);
    // Custom properties for sci-fi floating
    particle.userData = {
      angle: Math.random() * Math.PI * 2,
      radius: 3.5 + Math.random() * 2.5,
      speed: 0.003 + Math.random() * 0.004,
      y: (Math.random() - 0.5) * 2.2,
      phase: Math.random() * Math.PI * 2,
    };
    scene.add(particle);
    particles.push(particle);
  }

  window.addEventListener('resize', onWindowResize, false);
  animatePortal();
}

function animatePortal() {
  requestAnimationFrame(animatePortal);

  // Animate portal rotation
  portal.rotation.z += 0.006;

  // Sci-fi shimmer for portal opacity
  portal.material.opacity = 0.6 + 0.18 * Math.abs(Math.sin(Date.now() * 0.002));

  // Animate particles floating around the portal
  particles.forEach((p, i) => {
    p.userData.angle += p.userData.speed;
    p.userData.radius -= 0.0012; // Slowly spiral inward
    if (p.userData.radius < 1.2) {
      // Reset to outer edge
      p.userData.radius = 3.5 + Math.random() * 2.5;
      p.userData.angle = Math.random() * Math.PI * 2;
      p.userData.y = (Math.random() - 0.5) * 2.2;
    }
    p.position.x = Math.cos(p.userData.angle) * p.userData.radius;
    p.position.y = p.userData.y + Math.sin(Date.now() * 0.0005 + i) * 0.4;
    p.position.z = Math.sin(p.userData.angle) * p.userData.radius;
  });

  renderer.render(scene, camera);
}

function onWindowResize() {
  if (!renderer || !camera) return;
  const canvas = document.getElementById('portalCanvas');
  renderer.setSize(window.innerWidth, window.innerHeight);
  camera.aspect = window.innerWidth / window.innerHeight;
  camera.updateProjectionMatrix();
}

document.addEventListener('DOMContentLoaded', initPortalEffect);
