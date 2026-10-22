<?php

	/*
	/* global file */

	define('SS_NO_SMARTY', true);
	include('../../global.php');
	include('cohort.php');

	header('Content-Type: application/json');

	/*
	/* ADMIN endpoint — which crew have a SmartStaff photo, and when it was
	/* last written, for the Photo column on THE GOAT's Crew › Records
	/* (DESIGN-admin-crew-photo-update-v0_3.md §5.5, D5).
	/*
	/* One directory listing instead of one HTTP fetch per crew member.
	/* Read-only. No database access at all.
	/*
	/* Only the CURRENT photo of each person counts: crewimg_<id>.jpg. The
	/* backups admin-set-crew-photo.php keeps (crewimg_<id>_<time>[_n].jpg)
	/* do not match the pattern and are ignored.
	/*
	/* The date is filemtime(), i.e. when the file was last WRITTEN — not when
	/* the photo was taken. If the folder was ever copied/restored on the
	/* server those dates may all be the same; THE GOAT shows them as-is.
	/*
	/* Response: {"ok":true,"photos":{"<user id>":"YYYY-MM-DD"}}
	/*   - a person with no photo file is simply absent
	/*   - (object) on emit so an empty result is {} not []
	/*
	/* PHP 5.x — array(), no ??, no short arrays, tabs.
	*/

	/*
	/* 1. gate — admin only, GET only. */

	if (goat_user_cohort() !== 'admin')
	{
		goat_json_error(403, 'Admin only');
		exit;
	}

	if ($_SERVER['REQUEST_METHOD'] !== 'GET')
	{
		goat_json_error(405, 'GET required');
		exit;
	}

	/*
	/* 2. list the folder. Same path form as admin-set-crew-photo.php. */

	$picdir = BASEPATH . 'images/crewpics/';
	$names  = @scandir($picdir);

	if ($names === false)
	{
		error_log('admin-list-crew-photos: cannot read ' . $picdir);
		goat_json_error(500, 'cannot read photo folder');
		exit;
	}

	/*
	/* 3. keep crewimg_<id>.jpg only. Dates are Melbourne-local so they match
	/* what THE GOAT shows everywhere else. */

	$tz = new DateTimeZone('Australia/Melbourne');
	$photos = array();

	foreach ($names as $name)
	{
		if (!preg_match('/^crewimg_([0-9]+)\.jpg$/', $name, $m))
		{
			continue;
		}

		$mtime = @filemtime($picdir . $name);

		if ($mtime === false)
		{
			continue;
		}

		$d = new DateTime('@' . $mtime);
		$d->setTimezone($tz);
		$photos[(string) (int) $m[1]] = $d->format('Y-m-d');
	}

	echo json_encode(array('ok' => true, 'photos' => (object) $photos));
	exit;
