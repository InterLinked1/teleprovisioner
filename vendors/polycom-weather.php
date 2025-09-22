<?php
header("Content-Type: application/xhtml+xml"); /* This is the response type a Polycom wants */
?><!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd"><html><head>
<?php
polycomWeather($zip, $tz);
?>
</body></html>
<?php
function getCachedFile($cacheFile, $url, $noRecache = false) {
	global $weatherCacheDir, $weatherCacheTime;
	$response = null;
	if (str_ends_with($weatherCacheDir, "/")) {
		$weatherCacheDir = substr($weatherCacheDir, 0, -1);
	}
	$fullCacheFile = (strlen($weatherCacheDir) > 0 ? $weatherCacheDir : "/tmp") . "/" . $cacheFile;
	if (file_exists($fullCacheFile) && ($noRecache || filemtime($fullCacheFile) > time() - $weatherCacheTime)) {
		$response = file_get_contents($fullCacheFile);
	} else {
		$response = file_get_contents($url);
		file_put_contents($fullCacheFile, $response);
	}
	if ($response === false) {
		provLog("Failed to fetch data from $url");
	}
	return $response;
}
function getWeatherAPIData($zip) {
	global $openWeatherMapAPIKey;
	$url = "http://api.openweathermap.org/data/2.5/forecast?zip=$zip&lang=en&units=imperial&APPID=$openWeatherMapAPIKey";
	return getCachedFile("forecast_${zip}.json", $url);
}
function getAirPollutionByCoords($lat, $long) {
	global $openWeatherMapAPIKey;
	$url = "http://api.openweathermap.org/data/2.5/air_pollution?lat=$lat&lon=$long&appid=$openWeatherMapAPIKey";
	return getCachedFile("aqi_${lat}_${long}.json", $url);
}
function getCoordinatesFromZIP($zip) {
	global $openWeatherMapAPIKey;
	$url = "http://api.openweathermap.org/geo/1.0/zip?zip=$zip,US&appid=$openWeatherMapAPIKey";
	return getCachedFile("coords_${zip}.json", $url, true); /* Response will never change so once cached, always reuse */
}
function getAirPollutionByZIP($zip) {
	$coords = getCoordinatesFromZIP($zip);
	$json = json_decode($coords, true);
	if (!$json) {
		return false;
	}
	$lat = $json['lat'];
	$long = $json['lon'];
	if (!$lat || !$long) {
		provLog("Couldn't parse response: $coords");
		return false;
	}
	return getAirPollutionByCoords($lat, $long);
}

function polycomWeather($zip, $tz) {
	$today = new DateTime("now", new DateTimeZone($tz));
	$time = $today->format('g:i A');
	$json = getWeatherAPIData($zip);
	$jsonAirPollution = getAirPollutionByZIP($zip);
	if ($json === false || $jsonAirPollution === false) {
		provFail("Failed to fetch weather");
		return;
	}
	$precipitations = array();
	$w = json_decode($json, true);
	$jsonAirPollution = json_decode($jsonAirPollution, true);
	$airPollution = $jsonAirPollution['list'][0];
	$aqi = (int) $airPollution['main']['aqi'];
	switch ($aqi) { /* https://openweathermap.org/api/air-pollution#fields */
		case 1:
			$aqi = "Good";
			break;
		case 2:
			$aqi = "Fair";
			break;
		case 3:
			$aqi = "Modr";
			break;
		case 4:
			$aqi = "Poor";
			break;
		case 5:
			$aqi = "Bad"; /* Very Bad */
			break;
		default:
			break;
	}
	$pm25 = $airPollution['components']['pm2_5'];
	if ($pm25 < 10) {
		$pm25 = round($pm25, 1);
	} else {
		$pm25 = round($pm25, 0);
	}
	$sunrise = $w['city']['sunrise'];
	$sunset = $w['city']['sunset'];

	/* In case we are using a cached response,
	 * find the index that contains the current forecast. */
	$i = 0;
	$now = time();
	for (;; $i++) {
		if (!isset($w['list'][$i]['dt'])) {
			provFail("Forecast is stale, no current weather data");
			return;
		}
		$dt = $w['list'][$i]['dt'];
		if ($now < $dt) {
			/* If the first time here is in the future, then we use that */
			break;
		}
	}
	$arr = $w['list'][$i];

	$tempF = round($arr['main']['temp']);
	$tempC = round(($arr['main']['temp'] - 32) / 1.8);
	$tempFeelsF = round($arr['main']['feels_like']);
	$humidity = round($arr['main']['humidity']);
	$pressure = round($arr['main']['pressure']); /* hPA */
	$pressure = round(($pressure / 1013.25) * 29.9213, 2); /* hPA to inches */
	$clouds = $arr['clouds']['all'];
	$wind = round($arr['wind']['speed']);
	$windDir = $arr['wind']['deg'];
	$weather = ucfirst($arr['weather'][0]['description']); /* change lowercase to first letter capitalized */
	$pop = 100.0 * $arr['pop'];
	$dt = $arr['dt'];
	$dtObj = (new Datetime("@$dt"))->setTimezone(new DateTimeZone($tz));
	$dayOfMonth = $dtObj->format('j');
	$dayOfMonth2 = $dtObj->modify('+1 day')->format('j');
	$minTemp = array();
	$maxTemp = array();
	$minTemp2 = array();
	$maxTemp2 = array();
	$precip = array();
	$precip2 = array();
	$tomorrow = "Tomorrow";
	$weather2 = "";
	/* Calculate high/low for the entire day from this point forward */
	$c = 0;
	foreach ($w['list'] as $a => $arr) { /* 3 hour increments */
		if ($c < $i) {
			/* Ignore everything in the past until we get to right now */
			$c++;
			continue; 
		}
		$dt = $arr['dt'];
		$dtObj = (new Datetime("@$dt"))->setTimezone(new DateTimeZone($tz));
		$dtDOM = $dtObj->format('j'); /* get day of month */
		if ($dtDOM == $dayOfMonth) {
			$minTemp[] = $arr['main']['temp_min'];
			$maxTemp[] = $arr['main']['temp_max'];
			$precip[] = 100 * $arr['pop'];
		} else if ($dtDOM == $dayOfMonth2) {
			$minTemp2[] = $arr['main']['temp_min'];
			$maxTemp2[] = $arr['main']['temp_max'];
			$precip2[] = 100 * $arr['pop'];
			$tomorrow = $dtObj->format('l'); /* day of week name */
			if ($dtObj->format('G') === 8) {
				/* Use weather conditions tomorrow morning ~9am */
				$weather2 = ucfirst($arr['weather'][0]['description']); /* change lowercase to first letter capitalized */
			}
		} else {
			/* Finalize averages */
			$minTemp = round(min($minTemp));
			$maxTemp = round(max($maxTemp));
			$minTemp2 = round(min($minTemp2));
			$maxTemp2 = round(max($maxTemp2));
			$precip = max($precip);
			$precip2 = max($precip2);
			break;
		}
	}

	$sunriseF = new DateTime("@$sunrise");
	$sunsetF = new DateTime("@$sunset");
	$sunriseF->setTimezone(new DateTimeZone($tz));
	$sunsetF->setTimezone(new DateTimeZone($tz));
	$sunriseF = $sunriseF->format('g:i a');
	$sunsetF = $sunsetF->format('g:i a');

	$windDirName = wind_degrees_to_direction($windDir);

	$lunar_phase = ''; /* XXX not available in main API */
	$now = time();
	$currentTime = (new Datetime("@$now"))->setTimezone(new DateTimeZone($tz));
	$pm = ($currentTime->format('a') == 'pm');
	$hex = ($currentTime->format('G') < 4 || $currentTime->format('G') >= 20) ? 'e' : 'd'; /* e for night and d for day. Hour is 0-indexed! */
	$glyphArray = [
		'and'=>['&#x12b;',(bool)false],
		'Areas'=>['&#x1f4;',(bool)false],
		'Becoming Cloudy'=>['&#x1a7;',(bool)false],
		'Becoming Sunny'=>['&#x1a6;',(bool)false],
		'Blizzard'=>['&#x1ab;',(bool)false],
		'Blowing Dust'=>['&#x1db;',(bool)false],
		'Blowing Snow'=>['&#x1eb;',(bool)true],
		'Blustery'=>['&#x1f5;',(bool)false], /* 15-25 mph = Blustery (cold weather) */
		'Blustery.'=>['&#x1f5;&#x12b;',(bool)false],
		'Breezy'=>['&#x1f5;',(bool)false], /* 15-25 mph = Breezy (mild weather) */
		'Breezy.'=>['&#x1f5;&#x12b;',(bool)false],
		'Clear'=>['&#x19'.$lunar_phase.';',(bool)false],  
		'Clearing'=>[$now > $sunrise && $now < $sunset && !$pm ? '&#x1'.$hex.'9;' : '&#x18'.$lunar_phase.';',(bool)false], /* Daylight ? Decreasing Clouds : Mostly Clear */
		'Cloudy'=>['&#x1'.$hex.'4;',(bool)false],
		'Cold'=>['&#x1f1;',(bool)false],
		'Decreasing Clouds'=>['&#x1'.$hex.'9;',(bool)false],                                         
		'Dense Fog'=>['&#x1c8;',(bool)false],
		'Dense Freezing Fog'=>['&#x1c7;',(bool)false],   
		'Drizzle'=>['&#x1e0;',(bool)true],
		'Drizzle/Freezing Drizzle'=>['&#x1cc;',(bool)true],
		'Drizzle/Freezing Rain'=>['&#x191;',(bool)true],
		'Drizzle/Snow'=>['&#x1c3;',(bool)true],
		'Fair'=>['&#x19'.$lunar_phase.';',(bool)false],
		'Flurries'=>['&#x1c6;',(bool)true],
		'Fog'=>['&#x1e5;',(bool)false],
		'Freezing Drizzle'=>['&#x1d3;',(bool)true],
		'Freezing Fog'=>['&#x1ea;',(bool)false],
		'Freezing Rain'=>['&#x1ac;',(bool)true],
		'Frost'=>['&#x1e7;',(bool)false],
		'Gradual Clearing'=>['&#x1'.$hex.'9;',(bool)false],
		'Haze'=>['&#x1dd;',(bool)false],
		'Heavy Rain'=>['&#x1d2;',(bool)true],
		'Heavy Snow'=>['&#x1de;',(bool)true],
		'Hot'=>['&#x1cd;',(bool)false],
		'Ice Crystals'=>['&#x1d7;',(bool)false],
		'Ice Fog'=>['&#x1ea;',(bool)false],
		'Ice Pellets'=>['&#x1d7;',(bool)false],
		'Increasing Clouds'=>['&#x1'.$hex.'8;',(bool)false],                                           
		'Light Rain'=>['&#x190;',(bool)true],
		'Light Snow'=>['&#x1ad;',(bool)true],
		'Mostly Clear'=>['&#x18'.$lunar_phase.';',(bool)false],
		'Mostly Cloudy'=>['&#x1'.$hex.'f;',(bool)false],
		'Mostly Sunny'=>['&#x1e2;',(bool)false],
		'Partly Cloudy'=>['&#x1'.$hex.'c;',(bool)false],
		'Partly Sunny'=>['&#x1f6;',(bool)false],
		'Patchy'=>['&#x1d1;',(bool)false],
		'Rain'=>['&#x1f2;',(bool)true],
		'Rain/Flurries'=>['&#x1c9;',(bool)true],
		'Rain/Freezing Drizzle'=>['&#x1cb;',(bool)true],
		'Rain/Freezing Rain'=>['&#x1c5;',(bool)true],
		'Rain/Sleet'=>['&#x1c5;',(bool)true],
		'Rain/Snow'=>['&#x1f9;',(bool)true],
		'Severe Tstms'=>['&#x1cf;',(bool)true], 
		'Showers'=>['&#x1f2;',(bool)true],
		'Sleet'=>['&#x1d7;',(bool)true],
		'Smoke'=>['&#x1e6;',(bool)false],
		'Snow'=>['&#x1e3;',(bool)true],
		'Snow Showers'=>['&#x1e3;',(bool)true],
		'Snow/Sleet'=>['&#x1c2;',(bool)true],
		'Sprinkles'=>['&#x1e0;',(bool)true],
		'Sprinkles/Flurries'=>['&#x1ca;',(bool)true],
		'Sprinkles/Snow'=>['&#x1c3;',(bool)true],
		'Sunny'=>['&#x1fc;',(bool)false],
		'then'=>['&#x1fd;',(bool)false],
		'Thunderstorms'=>['&#x1cf;',(bool)true],
		'Tornado'=>['&#x1e1;',null], /* not in use */
		'T-storms'=>['&#x1cf;',(bool)true],
		'Umbrella'=>['&#x1a4;',null], /* no percentage precipitation catch all */
		'Very Windy'=>['&#x1c4;',(bool)false], /* 30-40 mph */
		'Very Windy.'=>['&#x1c4;&#x12b;',(bool)false],
		'Windy'=>['&#x1b9;',(bool)false], /* 20-30 mph */
		'Windy.'=>['&#x1b9;&#x12b;',(bool)false],
		'Wintry Mix'=>['&#x1a8;',(bool)true],
	];
	$sunriseSym = '&#x1fa;';
	$sunsetSym = '&#x1ed;';
	$highArr = "&#x1fe;";
	$lowArr = "&#x1ff;";
	$degrees = "&#x1b0;";

	/* If there is a symbol we can use, use it. Not perfect, given $glyphArray wasn't designed for openweathermap */
	$weatherSym = findGlyph($glyphArray, $weather);
	$weatherSym2 = findGlyph($glyphArray, $weather2);

	if ($pop > 0) {
		$precipNow = percentSymbol($pop) . $glyphArray['Showers'][0];
	} else {
		$precipNow = $glyphArray['Sunny'][0];
	}
	if ($pop > 0) {
		$precip = percentSymbol($precip) . $glyphArray['Showers'][0];
	} else {
		$precip = $glyphArray['Sunny'][0];
	}
	if ($pop > 0) {
		$precip2 = percentSymbol($precip2) . $glyphArray['Showers'][0];
	} else {
		$precip2 = $glyphArray['Sunny'][0];
	}
	echo "<title>$weather</title></head><body><hr/>" . PHP_EOL;
	echo "Temp: ${tempF}${degrees}F $sunriseSym $sunriseF $sunsetSym $sunsetF" . PHP_EOL;
	echo "<br/>Feels ${tempFeelsF}${degrees}, Wind: $wind mph $windDirName, $precipNow" . PHP_EOL;
	echo "<br/>RH $humidity%, BP $pressure\", AQI $aqi [$pm25 PM]" . PHP_EOL; /* PM 2.5 */
	echo "<br/>Today: ${highArr}${maxTemp}${degrees} ${lowArr}${minTemp}${degrees}, ${weatherSym} $precip" . PHP_EOL;
	echo "<br/>$tomorrow: ${highArr}${maxTemp2}${degrees} ${lowArr}${minTemp2}${degrees}, ${weatherSym2} $precip2";
}
function wind_degrees_to_direction($degrees) {
	$degrees -= (22.5 / 2); /* want to center, not start, at each threshold */
	if ($degrees < 0) {
		return "NNW";
	} if ($degrees < 22.5) {
		return "N";
	} else if ($degrees < 45) {
		return "NNE";
	} else if ($degrees < 67.5) {
		return "ENE";
	} else if ($degrees < 90) {
		return "E";
	} else if ($degrees < 112.5) {
		return "ESE";
	} else if ($degrees < 135) {
		return "SE";
	} else if ($degrees < 157.5) {
		return "SSE";
	} else if ($degrees < 180) {
		return "S";
	} else if ($degrees < 202.5) {
		return "SSW";
	} else if ($degrees < 225) {
		return "SW";
	} else if ($degrees < 247.5) {
		return "WSW";
	} else if ($degrees < 270) {
		return "W";
	} else if ($degrees < 292.5) {
		return "WNW";
	} else if ($degrees < 315) {
		return "NW";
	} else if ($degrees < 337.5) {
		return "NNW";
	} else if ($degrees < 360) {
		return "N"; /* could happen up to 360 - 22.5/2 */
	}
	provLog("Unexpected degrees: " . $degrees);
	return "";
}
function percentSymbol($pop) {
	if ($pop < 1) {
		return null;
	} else if ($pop < 20) {
		return "&#x178;";
	} else if ($pop < 30) {
		return "&#x179;";
	} else if ($pop < 40) {
		return "&#x17a;";
	} else if ($pop < 50) {
		return "&#x17b;";
	} else if ($pop < 60) {
		return "&#x17c;";
	} else if ($pop < 70) {
		return "&#x17d;";
	} else if ($pop < 80) {
		return "&#x17e;";
	} else if ($pop < 90) {
		return "&#x17f;";
	} else {
		return "&#x180;";
	}
}
function findGlyph($glyphArray, $weather) {
	foreach ($glyphArray as $key => $val) {
		if (strcasecmp($weather, $key) === 0) {
			return $val[0];
		}
	}
	return "";
}
?>