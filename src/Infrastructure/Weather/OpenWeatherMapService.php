<?php

declare(strict_types=1);

namespace App\Infrastructure\Weather;

use App\Domain\Model\Weather;

class OpenWeatherMapService implements WeatherService
{
    private string $apiKey;
    private static array $cache = [];

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }

    public function getWeather(float $lat, float $lon): Weather
    {
        // Simple cache by rounding coords to 1 decimal (~11km precision)
        $cacheKey = round($lat, 1) . '_' . round($lon, 1);
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $url = "https://api.openweathermap.org/data/2.5/weather?lat={$lat}&lon={$lon}&appid={$this->apiKey}&units=metric";
        
        $response = file_get_contents($url);
        if ($response === false) {
            throw new \RuntimeException("Failed to fetch weather data from OpenWeatherMap");
        }

        $data = json_decode($response, true);
        
        $weather = new Weather(
            isRaining: isset($data['rain']) || (isset($data['weather'][0]['main']) && $data['weather'][0]['main'] === 'Rain'),
            temperature: (float) $data['main']['temp'],
            windSpeed: (float) $data['wind']['speed'],
            humidity: (float) $data['main']['humidity']
            // feelsLike removed
        );

        self::$cache[$cacheKey] = $weather;
        return $weather;
    }
}
