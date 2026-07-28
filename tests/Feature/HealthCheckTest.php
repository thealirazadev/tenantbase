<?php

it('serves the framework health check', function (): void {
    $this->get('/up')->assertOk();
});
