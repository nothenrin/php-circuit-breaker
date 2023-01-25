<?php

namespace Henri1i\CircuitBreaker;

use Henri1i\CircuitBreaker\Domain\StoreRepository;

class CircuitBreaker
{
    private Config $config;
    private StoreRepository $store;

    public function __construct(
        private readonly string $service,
    ) {
        $this->config = new Config();
        $this->store = app(StoreRepository::class);
    }

    public function isAvailable(): bool
    {
        return ! $this->isOpen();
    }

    public function handleFailure(): void
    {
        if ($this->isHalfOpen()) {
            $this->openCircuit();

            return;
        }

        $this->incrementErrors();

        if ($this->reachedErrorThreshold() && ! $this->isOpen()) {
            $this->openCircuit();
        }
    }

    public function handleSuccess(): void
    {
        if (! $this->isHalfOpen()) {
            $this->reset();

            return;
        }

        $this->incrementSuccesses();

        if ($this->reachedSuccessThreshold()) {
            $this->reset();
        }
    }

    private function isOpen(): bool
    {
        return (bool) Cache::get($this->getKey(Key::OPEN), 0);
    }

    private function isHalfOpen(): bool
    {
        $isHalfOpen = (bool) Cache::get($this->getKey(Key::HALF_OPEN), 0);

        return ! $this->isOpen() && $isHalfOpen;
    }

    private function reachedErrorThreshold(): bool
    {
        $failures = $this->getErrorsCount();

        return $failures >= $this->config->errorThreshold;
    }

    private function reachedSuccessThreshold(): bool
    {
        $successes = $this->getSuccessesCount();

        return $successes >= $this->config->successThreshold;
    }

    private function incrementErrors(): void
    {
        $key = $this->getKey(Key::ERRORS);

        if (! Cache::get($key)) {
            Cache::put($key, 1, $this->config->timeoutWindow);
        }

        Cache::increment($key);
    }

    private function incrementSuccesses(): void
    {
        $key = $this->getKey(Key::SUCCESSES);

        if (! Cache::get($key)) {
            Cache::put($key, 1, $this->config->timeoutWindow);
        }

        Cache::increment($key);
    }

    private function reset(): void
    {
        foreach (Key::cases() as $key) {
            Cache::delete($this->getKey($key));
        }
    }

    private function setOpenCircuit(): void
    {
        Cache::put(
            $this->getKey(Key::OPEN),
            time(),
            $this->config->errorTimeout
        );
    }

    private function setHalfOpenCircuit(): void
    {
        Cache::put(
            $this->getKey(Key::HALF_OPEN),
            time(),
            $this->config->errorTimeout + $this->config->halfOpenTimeout
        );
    }

    private function getErrorsCount(): int
    {
        return (int) Cache::get(
            $this->getKey(Key::ERRORS),
            0
        );
    }

    private function getSuccessesCount(): int
    {
        return (int) Cache::get(
            $this->getKey(Key::SUCCESSES),
            0
        );
    }

    private function openCircuit(): void
    {
        $this->setOpenCircuit();
        $this->setHalfOpenCircuit();
    }

    private function getKey(?Key $key): string
    {
        return "circuit-breaker:{$this->service}:{$key?->value}";
    }
}