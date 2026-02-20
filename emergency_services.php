<?php
session_start();
require_once 'db.php';

// Check if user is logged in
$isLoggedIn = isset($_SESSION['user_id']);
$userId = $isLoggedIn ? $_SESSION['user_id'] : null;
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Emergency Services | SafeSpace</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --secondary-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --success-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            --danger-gradient: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
            --dark-bg: #0a0a14;
            --card-bg: rgba(255, 255, 255, 0.05);
            --card-border: rgba(255, 255, 255, 0.08);
            --text-primary: #ffffff;
            --text-secondary: rgba(255, 255, 255, 0.7);
            --text-muted: rgba(255, 255, 255, 0.5);
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: radial-gradient(ellipse at top, #1a1a2e 0%, #0f0f1a 50%, #0a0a14 100%);
            min-height: 100vh;
            color: var(--text-primary);
            overflow-x: hidden;
        }

        /* Animated Background Orbs */
        .bg-orbs {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            z-index: 0;
            pointer-events: none;
        }

        .orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(80px);
            opacity: 0.15;
            animation: float 20s ease-in-out infinite;
        }

        .orb1 {
            width: 400px;
            height: 400px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            top: -200px;
            left: -200px;
            animation-delay: 0s;
        }

        .orb2 {
            width: 500px;
            height: 500px;
            background: linear-gradient(135deg, #f093fb, #f5576c);
            bottom: -250px;
            right: -250px;
            animation-delay: -10s;
        }

        .orb3 {
            width: 350px;
            height: 350px;
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            animation-delay: -5s;
        }

        @keyframes float {
            0%, 100% { transform: translate(0, 0) scale(1); }
            33% { transform: translate(5px, -5px) scale(1.01); }
            66% { transform: translate(-3px, 4px) scale(0.99); }
        }

        /* Header */
        .header {
            position: sticky;
            top: 0;
            z-index: 1000;
            background: rgba(15, 15, 26, 0.85);
            backdrop-filter: blur(24px) saturate(180%);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            padding: 1rem 2rem;
            box-shadow:
                0 8px 32px rgba(0, 0, 0, 0.4),
                0 1px 0 rgba(255, 255, 255, 0.05) inset;
        }

        .header-content {
            max-width: 1600px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header-title {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .header-icon {
            width: 48px;
            height: 48px;
            background: var(--primary-gradient);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            box-shadow: 0 8px 24px rgba(102, 126, 234, 0.4);
            animation: pulse-glow 3s ease-in-out infinite;
        }

        @keyframes pulse-glow {
            0%, 100% { box-shadow: 0 8px 24px rgba(102, 126, 234, 0.4); }
            50% { box-shadow: 0 8px 32px rgba(102, 126, 234, 0.6), 0 0 40px rgba(102, 126, 234, 0.3); }
        }

        .header h1 {
            font-size: 1.5rem;
            font-weight: 800;
            letter-spacing: -0.5px;
            background: linear-gradient(135deg, #ffffff 0%, #a0a0ff 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .header-subtitle {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-top: 0.25rem;
            font-weight: 500;
        }

        .back-btn {
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.12);
            color: white;
            padding: 0.65rem 1.5rem;
            border-radius: 50px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .back-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);
            transition: left 0.5s;
        }

        .back-btn:hover::before {
            left: 100%;
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.15);
            border-color: rgba(255, 255, 255, 0.2);
            transform: translateX(-0.5px);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
        }

        /* Main Container */
        .main-container {
            position: relative;
            display: grid;
            grid-template-columns: 1fr 460px;
            height: calc(100vh - 94px);
            z-index: 1;
        }

        /* Map Section */
        .map-section {
            position: relative;
            border-right: 1px solid rgba(255, 255, 255, 0.08);
        }

        #map {
            width: 100%;
            height: 100%;
        }

        .map-controls {
            position: absolute;
            top: 20px;
            right: 20px;
            z-index: 500;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .map-btn {
            background: rgba(15, 15, 26, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.12);
            width: 56px;
            height: 56px;
            border-radius: 16px;
            color: white;
            font-size: 1.4rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4);
        }

        .map-btn:hover {
            background: rgba(102, 126, 234, 0.2);
            border-color: rgba(102, 126, 234, 0.4);
            transform: scale(1.01);
            box-shadow: 0 12px 32px rgba(102, 126, 234, 0.3);
        }

        .map-btn:active {
            transform: scale(0.95);
        }

        /* Sidebar */
        .sidebar {
            background: rgba(10, 10, 20, 0.95);
            backdrop-filter: blur(24px);
            overflow-y: auto;
            position: relative;
        }

        /* Search Bar */
        .search-section {
            padding: 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(0, 0, 0, 0.2);
        }

        .search-wrapper {
            position: relative;
        }

        .search-input {
            width: 100%;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 16px;
            padding: 0.9rem 1.2rem 0.9rem 3rem;
            color: white;
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.3s;
            outline: none;
        }

        .search-input::placeholder {
            color: var(--text-muted);
        }

        .search-input:focus {
            background: rgba(255, 255, 255, 0.08);
            border-color: rgba(102, 126, 234, 0.5);
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
        }

        .search-icon {
            position: absolute;
            left: 1.1rem;
            top: 50%;
            transform: translateY(-50%);
            font-size: 1.1rem;
            opacity: 0.5;
        }

        /* Filter Tabs */
        .filter-tabs {
            display: flex;
            overflow-x: auto;
            padding: 1.5rem;
            gap: 1rem;
            background: rgba(0, 0, 0, 0.3);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            scrollbar-width: thin;
            scrollbar-color: rgba(102, 126, 234, 0.5) rgba(255, 255, 255, 0.1);
        }

        .filter-tabs::-webkit-scrollbar {
            height: 8px;
        }

        .filter-tabs::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 4px;
            margin: 0 1.5rem;
        }

        .filter-tabs::-webkit-scrollbar-thumb {
            background: rgba(102, 126, 234, 0.5);
            border-radius: 4px;
            transition: background 0.3s;
        }

        .filter-tabs::-webkit-scrollbar-thumb:hover {
            background: rgba(102, 126, 234, 0.7);
        }

        .filter-tab {
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: var(--text-secondary);
            padding: 1rem 2rem;
            border-radius: 50px;
            cursor: pointer;
            white-space: nowrap;
            font-size: 1rem;
            font-weight: 600;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            gap: 0.8rem;
            position: relative;
            overflow: hidden;
            min-width: fit-content;
        }

        .filter-tab span:first-child {
            font-size: 1.5rem;
        }

        .filter-tab::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: var(--primary-gradient);
            opacity: 0;
            transition: opacity 0.3s;
        }

        .filter-tab span {
            position: relative;
            z-index: 1;
        }

        .filter-tab:hover {
            background: rgba(255, 255, 255, 0.12);
            border-color: rgba(255, 255, 255, 0.2);
            transform: translateY(-0.3px);
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
        }

        .filter-tab.active {
            color: white;
            border-color: transparent;
            box-shadow: 0 6px 24px rgba(102, 126, 234, 0.4);
        }

        .filter-tab.active::before {
            opacity: 1;
        }

        /* Helplines Section */
        .helplines-section {
            padding: 1.5rem;
            background: rgba(0, 0, 0, 0.25);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }

        .section-header {
            font-size: 0.75rem;
            font-weight: 700;
            margin-bottom: 1.1rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1.5px;
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }

        .section-header::before {
            content: '';
            width: 3px;
            height: 12px;
            background: var(--primary-gradient);
            border-radius: 2px;
        }

        .helpline-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.85rem;
        }

        .helpline-card {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            padding: 1.2rem;
            border-radius: 18px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            text-decoration: none;
            color: white;
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.15);
            box-shadow: 0 4px 16px rgba(220, 53, 69, 0.3);
        }

        .helpline-card::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.15) 0%, transparent 50%);
            transform: rotate(45deg);
            transition: all 0.5s;
        }

        .helpline-card:hover::before {
            left: 100%;
        }

        .helpline-card:hover {
            transform: translateY(-0.5px) scale(1.005);
            box-shadow: 0 12px 32px rgba(220, 53, 69, 0.5);
        }

        .helpline-card.women {
            background: linear-gradient(135deg, #9b59b6 0%, #8e44ad 100%);
            box-shadow: 0 4px 16px rgba(155, 89, 182, 0.3);
        }

        .helpline-card.women:hover {
            box-shadow: 0 12px 32px rgba(155, 89, 182, 0.5);
        }

        .helpline-number {
            font-size: 1.65rem;
            font-weight: 900;
            letter-spacing: 2px;
            text-shadow: 0 2px 12px rgba(0, 0, 0, 0.3);
            position: relative;
            z-index: 1;
            margin-bottom: 0.3rem;
        }

        .helpline-name {
            font-size: 0.72rem;
            font-weight: 600;
            opacity: 0.95;
            position: relative;
            z-index: 1;
        }

        /* Services List */
        .services-list {
            padding: 1.5rem;
        }

        .services-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.25rem;
        }

        .services-count {
            background: var(--primary-gradient);
            padding: 0.4rem 1rem;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 700;
            box-shadow: 0 4px 16px rgba(102, 126, 234, 0.3);
            letter-spacing: 0.5px;
        }

        .service-card {
            background: rgba(255, 255, 255, 0.04);
            backdrop-filter: blur(12px);
            border-radius: 20px;
            padding: 1.4rem;
            margin-bottom: 1rem;
            border: 1px solid rgba(255, 255, 255, 0.08);
            cursor: pointer;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .service-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -4px;
            width: 4px;
            height: 100%;
            background: var(--primary-gradient);
            opacity: 0;
            transition: all 0.3s;
        }

        .service-card::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: var(--primary-gradient);
            opacity: 0;
            transition: opacity 0.3s;
            z-index: 0;
        }

        .service-card:hover::before {
            opacity: 1;
            left: 0;
        }

        .service-card:hover::after {
            opacity: 0.05;
        }

        .service-card:hover {
            background: rgba(255, 255, 255, 0.08);
            border-color: rgba(102, 126, 234, 0.3);
            transform: translateX(1px);
            box-shadow:
                -8px 0 32px rgba(102, 126, 234, 0.2),
                0 8px 32px rgba(0, 0, 0, 0.3);
        }

        .service-content {
            position: relative;
            z-index: 1;
        }

        .service-header {
            display: flex;
            gap: 1rem;
            margin-bottom: 0.85rem;
        }

        .service-icon {
            font-size: 2.5rem;
            filter: drop-shadow(0 4px 12px rgba(0, 0, 0, 0.4));
        }

        .service-info {
            flex: 1;
        }

        .service-title-row {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.4rem;
        }

        .service-name {
            font-weight: 800;
            font-size: 1.05rem;
            letter-spacing: -0.3px;
            line-height: 1.3;
        }

        .verified-badge {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            box-shadow: 0 2px 8px rgba(79, 172, 254, 0.5);
            flex-shrink: 0;
        }

        .service-name-bn {
            font-size: 0.82rem;
            color: var(--text-secondary);
            font-weight: 500;
        }

        .service-meta {
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }

        .service-distance {
            background: rgba(102, 126, 234, 0.15);
            border: 1px solid rgba(102, 126, 234, 0.25);
            padding: 0.35rem 0.8rem;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 700;
            color: #8b9aff;
            display: flex;
            align-items: center;
            gap: 0.35rem;
        }

        .tags-row {
            display: flex;
            gap: 0.6rem;
            flex-wrap: wrap;
            margin: 0.85rem 0;
        }

        .service-badge {
            background: rgba(102, 126, 234, 0.2);
            border: 1px solid rgba(102, 126, 234, 0.3);
            padding: 0.3rem 0.8rem;
            border-radius: 50px;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.3px;
        }

        .service-badge.police {
            background: rgba(52, 152, 219, 0.2);
            border-color: rgba(52, 152, 219, 0.35);
            color: #6eb8ff;
        }
        .service-badge.hospital {
            background: rgba(46, 204, 113, 0.2);
            border-color: rgba(46, 204, 113, 0.35);
            color: #6effa6;
        }
        .service-badge.fire {
            background: rgba(231, 76, 60, 0.2);
            border-color: rgba(231, 76, 60, 0.35);
            color: #ff8a7a;
        }
        .service-badge.women {
            background: rgba(155, 89, 182, 0.2);
            border-color: rgba(155, 89, 182, 0.35);
            color: #d89fff;
        }

        .womens-cell-badge {
            background: linear-gradient(135deg, #9b59b6 0%, #8e44ad 100%);
            padding: 0.3rem 0.8rem;
            border-radius: 50px;
            font-size: 0.7rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            box-shadow: 0 2px 8px rgba(155, 89, 182, 0.4);
        }

        .service-details {
            display: flex;
            flex-direction: column;
            gap: 0.6rem;
            margin-top: 1rem;
            font-size: 0.85rem;
            color: var(--text-secondary);
        }

        .service-detail {
            display: flex;
            align-items: center;
            gap: 0.7rem;
        }

        .detail-icon {
            opacity: 0.7;
            font-size: 1rem;
        }

        .service-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
            margin-top: 1.25rem;
        }

        .action-btn {
            padding: 0.85rem;
            border: none;
            border-radius: 14px;
            font-size: 0.85rem;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.6rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            text-decoration: none;
            position: relative;
            overflow: hidden;
        }

        .action-btn::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }

        .action-btn:active::before {
            width: 300px;
            height: 300px;
        }

        .action-btn span {
            position: relative;
            z-index: 1;
        }

        .action-btn.call {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            color: white;
            box-shadow: 0 4px 16px rgba(56, 239, 125, 0.3);
        }

        .action-btn.call:hover {
            transform: translateY(-0.5px);
            box-shadow: 0 8px 24px rgba(56, 239, 125, 0.4);
        }

        .action-btn.directions {
            background: rgba(255, 255, 255, 0.08);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.15);
        }

        .action-btn.directions:hover {
            background: rgba(255, 255, 255, 0.15);
            transform: translateY(-0.5px);
        }

        /* Loading & Empty States */
        .loading, .empty-state {
            text-align: center;
            padding: 3.5rem 2rem;
            color: var(--text-muted);
        }

        .loading-spinner {
            width: 56px;
            height: 56px;
            border: 4px solid rgba(255, 255, 255, 0.08);
            border-top-color: #667eea;
            border-right-color: #764ba2;
            border-radius: 50%;
            animation: spin 1s cubic-bezier(0.5, 0, 0.5, 1) infinite;
            margin: 0 auto 1.5rem;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .empty-state-icon {
            font-size: 4.5rem;
            margin-bottom: 1.25rem;
            opacity: 0.4;
            filter: grayscale(1);
        }

        .empty-state p {
            font-size: 1rem;
            font-weight: 600;
        }

        /* Custom Popup */
        .leaflet-popup-content-wrapper {
            background: rgba(10, 10, 20, 0.98);
            backdrop-filter: blur(24px);
            border-radius: 20px;
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.12);
            box-shadow: 0 12px 48px rgba(0, 0, 0, 0.6);
            padding: 0.5rem;
        }

        .leaflet-popup-tip {
            background: rgba(10, 10, 20, 0.98);
            border: 1px solid rgba(255, 255, 255, 0.12);
        }

        .popup-content {
            min-width: 240px;
        }

        .popup-content h4 {
            margin-bottom: 0.75rem;
            font-size: 1.1rem;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }

        .popup-content p {
            font-size: 0.85rem;
            color: var(--text-secondary);
            margin: 0.4rem 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .popup-content .call-btn {
            display: block;
            width: 100%;
            margin-top: 1rem;
            padding: 0.75rem;
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            border: none;
            border-radius: 12px;
            color: white;
            text-align: center;
            text-decoration: none;
            font-weight: 800;
            font-size: 0.9rem;
            transition: all 0.3s;
        }

        .popup-content .call-btn:hover {
            transform: scale(1.03);
            box-shadow: 0 6px 20px rgba(56, 239, 125, 0.4);
        }

        /* Scrollbar */
        .sidebar::-webkit-scrollbar {
            width: 8px;
        }

        .sidebar::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.02);
        }

        .sidebar::-webkit-scrollbar-thumb {
            background: rgba(102, 126, 234, 0.3);
            border-radius: 10px;
        }

        .sidebar::-webkit-scrollbar-thumb:hover {
            background: rgba(102, 126, 234, 0.5);
        }

        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(24px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .service-card {
            animation: fadeInUp 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            animation-fill-mode: both;
        }

        .service-card:nth-child(1) { animation-delay: 0.05s; }
        .service-card:nth-child(2) { animation-delay: 0.1s; }
        .service-card:nth-child(3) { animation-delay: 0.15s; }
        .service-card:nth-child(4) { animation-delay: 0.2s; }
        .service-card:nth-child(5) { animation-delay: 0.25s; }

        /* Responsive */
        @media (max-width: 1200px) {
            .main-container {
                grid-template-columns: 1fr 400px;
            }
        }

        @media (max-width: 900px) {
            .main-container {
                grid-template-columns: 1fr;
                grid-template-rows: 50vh 1fr;
            }

            .sidebar {
                border-left: none;
                border-top: 1px solid rgba(255, 255, 255, 0.1);
            }

            .map-section {
                border-right: none;
            }

            .header {
                padding: 1rem 1.25rem;
            }

            .helpline-grid {
                grid-template-columns: 1fr;
            }

            .service-actions {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Background Orbs -->
    <div class="bg-orbs">
        <div class="orb orb1"></div>
        <div class="orb orb2"></div>
        <div class="orb orb3"></div>
    </div>

    <!-- Header -->
    <header class="header">
        <div class="header-content">
            <div class="header-title">
                <div class="header-icon">🚨</div>
                <div>
                    <h1>Emergency Services</h1>
                    <div class="header-subtitle">Find help near you quickly</div>
                </div>
            </div>
            <a href="dashboard.php" class="back-btn">
                <span>←</span>
                <span>Dashboard</span>
            </a>
        </div>
    </header>

    <!-- Main Content -->
    <div class="main-container">
        <!-- Map Section -->
        <div class="map-section">
            <div id="map"></div>
            <div class="map-controls">
                <button class="map-btn" onclick="locateUser()" title="Find My Location">
                    📍
                </button>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="sidebar">
            <!-- Search Bar -->
            <div class="search-section">
                <div class="search-wrapper">
                    <span class="search-icon">🔍</span>
                    <input
                        type="text"
                        class="search-input"
                        placeholder="Search for services..."
                        id="search-input"
                    >
                </div>
            </div>

            <!-- Filter Tabs -->
            <div class="filter-tabs">
                <button class="filter-tab active" data-type="all">
                    <span>🔍</span>
                    <span>All</span>
                </button>
                <button class="filter-tab" data-type="police_station">
                    <span>👮</span>
                    <span>Police Stations</span>
                </button>
                <button class="filter-tab" data-type="hospital">
                    <span>🏥</span>
                    <span>Hospitals</span>
                </button>
                <button class="filter-tab" data-type="womens_helpdesk">
                    <span>👩‍⚖️</span>
                    <span>Women's Helpdesks</span>
                </button>
                <button class="filter-tab" data-type="fire_station">
                    <span>🚒</span>
                    <span>Fire Stations</span>
                </button>
                <button class="filter-tab" data-type="ngo">
                    <span>🤝</span>
                    <span>NGOs</span>
                </button>
            </div>

            <!-- Helplines Section -->
            <div class="helplines-section">
                <h3 class="section-header">
                    জরুরি হেল্পলাইন
                </h3>
                <div class="helpline-grid" id="helplines-grid">
                    <!-- Helplines will be loaded here -->
                </div>
            </div>

            <!-- Services List -->
            <div class="services-list">
                <div class="services-header">
                    <h3 class="section-header">
                        Nearby Services
                    </h3>
                    <span class="services-count" id="services-count">0 found</span>
                </div>
                <div id="services-container">
                    <div class="loading">
                        <div class="loading-spinner"></div>
                        <p>Finding services near you...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        // Global variables
        let map;
        let userMarker;
        let serviceMarkers = [];
        let allServices = [];
        let userLat = 23.7465;
        let userLng = 90.3762;
        let currentFilter = 'all';

        // Initialize map
        function initMap() {
            map = L.map('map', {
                zoomControl: false
            }).setView([userLat, userLng], 13);

            L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
                attribution: '© OpenStreetMap contributors © CARTO',
                maxZoom: 19
            }).addTo(map);

            L.control.zoom({
                position: 'bottomleft'
            }).addTo(map);

            locateUser();
        }

        // Get user location
        function locateUser() {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    (position) => {
                        userLat = position.coords.latitude;
                        userLng = position.coords.longitude;

                        map.setView([userLat, userLng], 14);

                        if (userMarker) {
                            userMarker.setLatLng([userLat, userLng]);
                        } else {
                            userMarker = L.marker([userLat, userLng], {
                                icon: L.divIcon({
                                    className: 'user-marker',
                                    html: `<div style="
                                        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                                        width: 28px;
                                        height: 28px;
                                        border-radius: 50%;
                                        border: 4px solid white;
                                        box-shadow: 0 0 24px rgba(102, 126, 234, 0.8), 0 0 48px rgba(102, 126, 234, 0.4);
                                        animation: pulse-marker 2s ease-in-out infinite;
                                    "></div>
                                    <style>
                                        @keyframes pulse-marker {
                                            0%, 100% { transform: scale(1); }
                                            50% { transform: scale(1.1); }
                                        }
                                    </style>`,
                                    iconSize: [28, 28],
                                    iconAnchor: [14, 14]
                                })
                            }).addTo(map);
                            userMarker.bindPopup('<strong>📍 Your Location</strong>');
                        }

                        loadServices();
                    },
                    (error) => {
                        console.log('Location error:', error);
                        loadServices();
                    }
                );
            } else {
                loadServices();
            }
        }

        // Load helplines
        async function loadHelplines() {
            try {
                const response = await fetch('api/emergency_services.php?helplines=1');
                const data = await response.json();

                if (data.success && data.data.length > 0) {
                    const grid = document.getElementById('helplines-grid');
                    const topHelplines = data.data.slice(0, 4);

                    grid.innerHTML = topHelplines.map(h => `
                        <a href="tel:${h.number}" class="helpline-card ${h.category === 'womens_rights' || h.category === 'domestic_violence' ? 'women' : ''}">
                            <div class="helpline-number">${h.number}</div>
                            <div class="helpline-name">${h.name_bn || h.name}</div>
                        </a>
                    `).join('');
                }
            } catch (error) {
                console.error('Error loading helplines:', error);
            }
        }

        // Load emergency services
        async function loadServices() {
            const container = document.getElementById('services-container');
            container.innerHTML = `
                <div class="loading">
                    <div class="loading-spinner"></div>
                    <p>Finding services near you...</p>
                </div>
            `;

            try {
                let url = `api/emergency_services.php?lat=${userLat}&lng=${userLng}&radius=10000`;
                if (currentFilter !== 'all') {
                    url += `&type=${currentFilter}`;
                }

                const response = await fetch(url);
                const data = await response.json();

                if (data.success) {
                    allServices = data.data;
                    displayServices(allServices);
                    displayMarkers(allServices);
                    document.getElementById('services-count').textContent = `${data.count} found`;
                }
            } catch (error) {
                console.error('Error loading services:', error);
                container.innerHTML = `
                    <div class="empty-state">
                        <div class="empty-state-icon">❌</div>
                        <p>Error loading services</p>
                    </div>
                `;
            }
        }

        // Display services in list
        function displayServices(services) {
            const container = document.getElementById('services-container');

            if (services.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <div class="empty-state-icon">🔍</div>
                        <p>No services found nearby</p>
                    </div>
                `;
                return;
            }

            container.innerHTML = services.map((service, index) => `
                <div class="service-card" onclick="focusService(${service.latitude}, ${service.longitude})" style="animation-delay: ${0.05 * (index % 5)}s;">
                    <div class="service-content">
                        <div class="service-header">
                            <span class="service-icon">${service.icon}</span>
                            <div class="service-info">
                                <div class="service-title-row">
                                    <div class="service-name">${service.name}</div>
                                    ${service.verified ? '<span class="verified-badge">✓</span>' : ''}
                                </div>
                                ${service.name_bn ? `<div class="service-name-bn">${service.name_bn}</div>` : ''}
                                <div class="service-meta">
                                    ${service.distance ? `
                                        <div class="service-distance">
                                            <span>📍</span>
                                            <span>${service.distance.text}</span>
                                        </div>
                                    ` : ''}
                                </div>
                            </div>
                        </div>

                        <div class="tags-row">
                            <span class="service-badge ${getTypeClass(service.type)}">
                                ${service.type_label.bn}
                            </span>
                            ${service.has_womens_cell ? `
                                <span class="womens-cell-badge">
                                    <span>👩</span>
                                    <span>নারী সেল</span>
                                </span>
                            ` : ''}
                        </div>

                        <div class="service-details">
                            ${service.address ? `
                                <div class="service-detail">
                                    <span class="detail-icon">📍</span>
                                    <span>${service.address}</span>
                                </div>
                            ` : ''}
                            ${service.phone ? `
                                <div class="service-detail">
                                    <span class="detail-icon">📞</span>
                                    <span>${service.phone}</span>
                                </div>
                            ` : ''}
                            <div class="service-detail">
                                <span class="detail-icon">🕐</span>
                                <span>${service.operating_hours}</span>
                            </div>
                        </div>

                        <div class="service-actions">
                            ${service.phone ? `
                                <a href="tel:${service.phone}" class="action-btn call" onclick="event.stopPropagation()">
                                    <span>📞</span>
                                    <span>Call Now</span>
                                </a>
                            ` : ''}
                            <a href="https://www.google.com/maps/dir/?api=1&destination=${service.latitude},${service.longitude}"
                               target="_blank" class="action-btn directions" onclick="event.stopPropagation()">
                                <span>🗺️</span>
                                <span>Directions</span>
                            </a>
                        </div>
                    </div>
                </div>
            `).join('');
        }

        // Get type class for badge
        function getTypeClass(type) {
            const classes = {
                'police_station': 'police',
                'hospital': 'hospital',
                'fire_station': 'fire',
                'womens_helpdesk': 'women',
                'ngo': 'women'
            };
            return classes[type] || '';
        }

        // Display markers on map
        function displayMarkers(services) {
            serviceMarkers.forEach(marker => map.removeLayer(marker));
            serviceMarkers = [];

            services.forEach(service => {
                const markerColor = getMarkerColor(service.type);

                const marker = L.marker([service.latitude, service.longitude], {
                    icon: L.divIcon({
                        className: 'service-marker',
                        html: `<div style="
                            background: ${markerColor};
                            width: 44px;
                            height: 44px;
                            border-radius: 50%;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                            font-size: 22px;
                            border: 3px solid white;
                            box-shadow: 0 6px 20px rgba(0,0,0,0.5);
                            transition: all 0.3s;
                        ">${service.icon}</div>`,
                        iconSize: [44, 44],
                        iconAnchor: [22, 22]
                    })
                }).addTo(map);

                const popupContent = `
                    <div class="popup-content">
                        <h4>${service.icon} ${service.name}</h4>
                        ${service.name_bn ? `<p style="font-style: italic; opacity: 0.8;">${service.name_bn}</p>` : ''}
                        <p><span>📍</span> ${service.address || 'Address not available'}</p>
                        <p><span>🕐</span> ${service.operating_hours}</p>
                        ${service.phone ? `
                            <a href="tel:${service.phone}" class="call-btn">
                                📞 Call ${service.phone}
                            </a>
                        ` : ''}
                    </div>
                `;

                marker.bindPopup(popupContent);
                serviceMarkers.push(marker);
            });
        }

        // Get marker color by type
        function getMarkerColor(type) {
            const colors = {
                'police_station': 'linear-gradient(135deg, #3498db 0%, #2980b9 100%)',
                'hospital': 'linear-gradient(135deg, #2ecc71 0%, #27ae60 100%)',
                'fire_station': 'linear-gradient(135deg, #e74c3c 0%, #c0392b 100%)',
                'womens_helpdesk': 'linear-gradient(135deg, #9b59b6 0%, #8e44ad 100%)',
                'ngo': 'linear-gradient(135deg, #9b59b6 0%, #8e44ad 100%)'
            };
            return colors[type] || 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)';
        }

        // Focus on service
        function focusService(lat, lng) {
            map.setView([lat, lng], 16);

            serviceMarkers.forEach(marker => {
                const markerLatLng = marker.getLatLng();
                if (Math.abs(markerLatLng.lat - lat) < 0.0001 && Math.abs(markerLatLng.lng - lng) < 0.0001) {
                    marker.openPopup();
                }
            });
        }

        // Search functionality
        const searchInput = document.getElementById('search-input');
        let searchTimeout;

        searchInput.addEventListener('input', (e) => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                const query = e.target.value.toLowerCase().trim();

                if (query === '') {
                    displayServices(allServices);
                    displayMarkers(allServices);
                    document.getElementById('services-count').textContent = `${allServices.length} found`;
                    return;
                }

                const filtered = allServices.filter(service => {
                    return service.name.toLowerCase().includes(query) ||
                           (service.name_bn && service.name_bn.includes(query)) ||
                           (service.address && service.address.toLowerCase().includes(query));
                });

                displayServices(filtered);
                displayMarkers(filtered);
                document.getElementById('services-count').textContent = `${filtered.length} found`;
            }, 300);
        });

        // Filter tabs
        document.querySelectorAll('.filter-tab').forEach(tab => {
            tab.addEventListener('click', () => {
                document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                currentFilter = tab.dataset.type;
                searchInput.value = '';
                loadServices();
            });
        });

        // Initialize
        document.addEventListener('DOMContentLoaded', () => {
            initMap();
            loadHelplines();
        });
    </script>
</body>
</html>
