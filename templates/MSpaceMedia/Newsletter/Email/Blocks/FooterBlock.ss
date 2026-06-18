<tr>
    <td style="$InlineStyle;font-family:$EffectiveFont;font-size:12px;line-height:1.5;<% if not $TextColor %>color:$Brand.FooterTextColor;<% end_if %>">
        $Content
        <p style="margin:10px 0 0;">
            <a href="$ViewOnlineLink.ATT" target="_blank" style="color:$Brand.FooterTextColor;text-decoration:underline;"><%t MSpaceMedia\Newsletter\Email\Blocks\FooterBlock.VIEW_ONLINE 'View online' %></a>
            &nbsp;|&nbsp;
            <a href="$UnsubscribeLink.ATT" target="_blank" style="color:$Brand.FooterTextColor;text-decoration:underline;"><%t MSpaceMedia\Newsletter\Email\Blocks\FooterBlock.UNSUBSCRIBE 'Unsubscribe' %></a>
        </p>
    </td>
</tr>
