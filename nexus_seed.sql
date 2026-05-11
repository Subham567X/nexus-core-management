-- FULL 3D ANIMATED FILM PRODUCTION & MANAGEMENT SYSTEM SEED
-- This script explicitly implements all teams, file rules, and statuses.

USE nexus_core;

-- Clear existing teams for clean seed
TRUNCATE TABLE teams;

-- 🔷 PRE-PRODUCTION
INSERT INTO teams (department, team_name, allowed_extensions) VALUES 
('Pre-Production', 'Story Development Team', 'PDF,DOCX,TXT'),
('Pre-Production', 'Storyboarding Team', 'PNG,JPG,PDF,PSD'),
('Pre-Production', 'Concept Art Team', 'BLEND,MA,MB,FBX,OBJ,GLB,PNG,JPG,PSD'),
('Pre-Production', 'Character Design Team', 'BLEND,MA,MB,FBX,OBJ,GLB,PNG,JPG,PSD'),
('Pre-Production', 'Research & Development Team', 'PDF,DOCX,TXT'),
('Pre-Production', 'Voice Planning Team', 'MP3,WAV,TXT');

-- 🔷 3D PRODUCTION
INSERT INTO teams (department, team_name, allowed_extensions) VALUES 
('3D Production', '3D Modeling Team', 'BLEND,MA,MB,FBX,OBJ,GLB,STL,ZTL'),
('3D Production', 'Texturing Team', 'PNG,JPG,PSD,SUBSTANCE FILES'),
('3D Production', 'Rigging Team', 'BLEND,MA,MB,FBX,OBJ,GLB'),
('3D Production', 'Animation Team', 'BLEND,MA,MB,FBX,GLB,MP4,MOV'),
('3D Production', 'Motion Capture Team', 'BVH,FBX,MP4'),
('3D Production', 'Environment Team', 'BLEND,MA,MB,FBX,OBJ,GLB,PNG,JPG'),
('3D Production', 'Lighting Team', 'BLEND,MA,MB,FBX,GLB,EXR,PNG'),
('3D Production', 'Camera & Cinematic Team', 'BLEND,MA,MB,FBX,GLB,MP4,MOV'),
('3D Production', 'Simulation Team', 'BLEND,MA,MB,FBX,GLB,VDB,CACHE FILES'),
('3D Production', 'VFX Team', 'BLEND,MA,MB,FBX,GLB,EXR,AE PROJECT,PNG SEQUENCE,MP4'),
('3D Production', 'Rendering Team', 'BLEND,MA,MB,FBX,GLB,EXR,PNG SEQUENCE,MP4');

-- 🔷 LIVE ACTING SECTION
INSERT INTO teams (department, team_name, allowed_extensions) VALUES 
('Live Acting', 'Live Acting Team', 'MP4,MOV,JPG');

-- 🔷 POST-PRODUCTION
INSERT INTO teams (department, team_name, allowed_extensions) VALUES 
('Post-Production', 'Compositing Team', 'EXR,NUKE FILES,AE FILES'),
('Post-Production', 'Video Editing Team', 'PR PROJECT,MP4,MOV'),
('Post-Production', 'Color Grading Team', 'LUT,MP4,MOV'),
('Post-Production', 'Sound Design Team', 'WAV,MP3,FLP'),
('Post-Production', 'Background Music Team', 'WAV,MP3,FL STUDIO PROJECT'),
('Post-Production', 'Voice Acting Team', 'WAV,MP3'),
('Post-Production', 'Singing Team', 'WAV,MP3,FLAC,OGG'),
('Post-Production', 'Music Production Team', 'FL STUDIO PROJECT,WAV,MP3,MIDI'),
('Post-Production', 'Lyrics Writing Team', 'TXT,DOCX,PDF'),
('Post-Production', 'Mixing & Mastering Team', 'WAV,FLP,MP3'),
('Post-Production', 'SFX Team', 'WAV,MP3,OGG,FLAC'),
('Post-Production', 'Foley Team', 'WAV,MP3,FLAC'),
('Post-Production', 'Ambient Sound Team', 'WAV,MP3,OGG'),
('Post-Production', 'Audio Cleanup Team', 'WAV,FLP,MP3'),
('Post-Production', 'Spatial Audio Team', 'WAV,MP3,MULTI-TRACK FILES'),
('Post-Production', 'Voice FX Team', 'WAV,MP3,FLP'),
('Post-Production', 'Final Mastering Team', 'MP4,MKV,MOV');

-- 🔷 CYBERSECURITY
INSERT INTO teams (department, team_name, allowed_extensions) VALUES 
('Cybersecurity', 'Cybersecurity Team', 'LOG,TXT,JSON,PDF');
