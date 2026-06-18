<tr<% if $HideOnMobile %> class="hide-mobile"<% end_if %>>
    <td style="$InlineStyle">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr>
                <td style="background-color:$BoxColorSafe;padding:16px;border-radius:6px;font-family:$EffectiveFont;font-size:16px;line-height:1.5;<% if not $TextColor %>color:$Brand.BodyTextColor;<% end_if %>">
                    $Content
                </td>
            </tr>
        </table>
    </td>
</tr>
