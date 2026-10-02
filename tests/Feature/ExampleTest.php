<?php

test('the application redirects guests to the owner login page', function () {
    $response = $this->get('/');

    $response->assertRedirect('/login');
});
