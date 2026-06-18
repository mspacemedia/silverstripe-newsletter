<tr<% if $HideOnMobile %> class="hide-mobile"<% end_if %>>
    <td style="$InlineStyle;font-family:$EffectiveFont;<% if not $TextColor %>color:$Brand.BodyTextColor;<% end_if %>">
        <% with $ScaledImage %>
            <img src="$AbsoluteURL" alt="$Up.Heading.ATT" width="$Width" style="display:block;border:0;outline:none;text-decoration:none;max-width:100%;height:auto;" />
        <% end_with %>
        <% if $Heading %><div style="font-size:20px;font-weight:bold;margin:12px 0 6px;color:$Brand.HeadingColor;">$Heading.XML</div><% end_if %>
        <% if $Content %><div style="font-size:15px;line-height:1.5;">$Content</div><% end_if %>
        <% if $ButtonURL %><div style="margin-top:12px;"><a href="$ButtonURL.ATT" target="_blank" style="$ButtonStyle">$ButtonLabel.XML</a></div><% end_if %>
    </td>
</tr>
