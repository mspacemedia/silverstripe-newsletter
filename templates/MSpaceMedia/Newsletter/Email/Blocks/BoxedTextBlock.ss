<tr<% if $HideOnMobile %> class="hide-mobile"<% end_if %>>
    <td class="$BlockClass" style="$InlineStyle">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:separate;">
            <tr>
                <td bgcolor="$BoxColorSafe" style="background-color:$BoxColorSafe;{$BoxBorderStyle}padding:20px 24px 8px;border-radius:8px;font-family:$EffectiveFont;font-size:16px;line-height:1.6;color:$EffectiveTextColor;">
                    $RenderedContent
                </td>
            </tr>
        </table>
    </td>
</tr>
