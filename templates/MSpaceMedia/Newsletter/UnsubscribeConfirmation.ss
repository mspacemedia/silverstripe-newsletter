<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="robots" content="noindex,nofollow" />
    <title><%t MSpaceMedia\Newsletter\UnsubscribeConfirmation.TITLE 'Unsubscribed' %></title>
    <style>
        body { font-family: Arial, Helvetica, sans-serif; background: #f4f4f4; color: #333; margin: 0; padding: 40px 20px; }
        .card { max-width: 480px; margin: 0 auto; background: #fff; border-radius: 6px; padding: 32px; text-align: center; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
        h1 { font-size: 22px; margin: 0 0 12px; }
        p { line-height: 1.5; margin: 0 0 8px; }
        .email { font-weight: bold; }
    </style>
</head>
<body>
    <div class="card">
        <h1><%t MSpaceMedia\Newsletter\UnsubscribeConfirmation.HEADING 'You have been unsubscribed' %></h1>
        <p><%t MSpaceMedia\Newsletter\UnsubscribeConfirmation.REMOVED_PREFIX 'We have removed' %> <span class="email">$Email.XML</span> <%t MSpaceMedia\Newsletter\UnsubscribeConfirmation.REMOVED_SUFFIX 'from our mailing list.' %></p>
        <p><%t MSpaceMedia\Newsletter\UnsubscribeConfirmation.NO_LONGER_RECEIVE 'You will no longer receive these newsletters.' %></p>
    </div>
</body>
</html>
